<?php

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Jobs\SendCompletionPrompt;
use App\Domain\Notifications\Jobs\SendDailyReview;
use App\Domain\Notifications\NotificationChannel;
use App\Models\DailyReview;
use App\Models\NotificationDelivery;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class UncertainDeliveryTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_prompt_is_not_resent_when_provider_may_have_accepted_it(): void
    {
        Queue::fake();
        $alice = $this->alice();
        $this->asTenant($alice, function (): void {
            $task = Task::factory()->create();
            TaskOccurrence::factory()->forTask($task)->at(now()->subHour()->format('Y-m-d H:i:s'))->create();
        });
        $this->artisan('nunez:dispatch-due')->assertSuccessful();
        $delivery = $this->asTenant($alice, fn () => NotificationDelivery::query()->sole());
        $channel = $this->uncertainChannel();

        try {
            $this->asTenant($alice, fn () => (new SendCompletionPrompt($delivery->id, $alice->id, $delivery->account_id))->handle($channel));
            $this->fail('Expected an uncertain provider result.');
        } catch (RuntimeException $e) {
            $this->assertSame('Provider response lost after acceptance.', $e->getMessage());
        }

        $this->assertDatabaseHas('notification_deliveries', ['id' => $delivery->id, 'status' => 'uncertain', 'last_error_code' => 'uncertain_send']);
        $this->asTenant($alice, fn () => (new SendCompletionPrompt($delivery->id, $alice->id, $delivery->account_id))->handle($channel));
        $this->assertSame(1, $channel->completionSends);
        Queue::fake();
        $this->travel(11)->minutes();
        $this->artisan('nunez:recover-deliveries')->assertSuccessful();
        Queue::assertNotPushed(SendCompletionPrompt::class);
    }

    public function test_daily_review_uses_the_same_uncertain_policy(): void
    {
        Queue::fake();
        $alice = $this->alice();
        $this->asTenant($alice, fn () => UserPreference::query()->where('user_id', $alice->id)->firstOrFail()->update([
            'timezone' => 'UTC', 'review_times' => ['morning' => '10:45', 'afternoon' => null, 'evening' => null],
        ]));
        $this->travelTo('2026-11-09 10:45:00 UTC');
        $this->artisan('nunez:dispatch-reviews')->assertSuccessful();
        $delivery = $this->asTenant($alice, fn () => NotificationDelivery::query()->sole());
        $channel = $this->uncertainChannel();

        try {
            $this->asTenant($alice, fn () => (new SendDailyReview($delivery->id, $alice->id, $delivery->account_id))->handle($channel));
            $this->fail('Expected an uncertain provider result.');
        } catch (RuntimeException $e) {
            $this->assertSame('Provider response lost after acceptance.', $e->getMessage());
        }

        $this->assertDatabaseHas('notification_deliveries', ['id' => $delivery->id, 'status' => 'uncertain', 'last_error_code' => 'uncertain_send']);
        $this->assertDatabaseHas('daily_reviews', ['delivery_id' => $delivery->id, 'status' => 'uncertain']);
        $this->asTenant($alice, fn () => (new SendDailyReview($delivery->id, $alice->id, $delivery->account_id))->handle($channel));
        $this->assertSame(1, $channel->reviewSends);
        Queue::fake();
        $this->travel(11)->minutes();
        $this->artisan('nunez:recover-deliveries')->assertSuccessful();
        Queue::assertNotPushed(SendDailyReview::class);
    }

    public function test_stale_daily_review_is_classified_without_resending(): void
    {
        Queue::fake();
        $alice = $this->alice();
        $this->asTenant($alice, fn () => UserPreference::query()->where('user_id', $alice->id)->firstOrFail()->update([
            'timezone' => 'UTC', 'review_times' => ['morning' => '10:45', 'afternoon' => null, 'evening' => null],
        ]));
        $this->travelTo('2026-11-09 10:45:00 UTC');
        $this->artisan('nunez:dispatch-reviews')->assertSuccessful();
        $delivery = $this->asTenant($alice, fn () => NotificationDelivery::query()->sole());
        $this->asTenant($alice, fn () => $delivery->forceFill(['status' => 'sending', 'updated_at' => now()->subMinutes(20)])->save());
        Queue::fake();

        $this->artisan('nunez:recover-deliveries')->assertSuccessful();

        $this->assertDatabaseHas('notification_deliveries', ['id' => $delivery->id, 'status' => 'uncertain']);
        $this->assertDatabaseHas('daily_reviews', ['delivery_id' => $delivery->id, 'status' => 'uncertain']);
        Queue::assertNotPushed(SendDailyReview::class);
    }

    private function uncertainChannel(): NotificationChannel
    {
        return new class implements NotificationChannel
        {
            public int $completionSends = 0;

            public int $reviewSends = 0;

            public function availableFor(User $user): bool
            {
                return true;
            }

            public function sendCompletionPrompt(User $user, TaskOccurrence $occurrence, NotificationDelivery $delivery): \App\Domain\Notifications\DeliveryResult
            {
                $this->completionSends++;
                throw new RuntimeException('Provider response lost after acceptance.');
            }

            public function sendDailyReview(User $user, DailyReview $review, NotificationDelivery $delivery): \App\Domain\Notifications\DeliveryResult
            {
                $this->reviewSends++;
                throw new RuntimeException('Provider response lost after acceptance.');
            }
        };
    }
}
