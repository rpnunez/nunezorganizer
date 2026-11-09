<?php

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\DeliveryResult;
use App\Domain\Notifications\Jobs\SendCompletionPrompt;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Scheduling\Actions\CancelOccurrence;
use App\Enums\OccurrenceState;
use App\Models\DailyReview;
use App\Models\NotificationDelivery;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class DispatchDueTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_repeated_dispatch_creates_one_logical_delivery_and_job(): void
    {
        Queue::fake();
        $alice = $this->alice();
        $occurrence = $this->asTenant($alice, function () {
            $task = Task::factory()->create();

            return TaskOccurrence::factory()->forTask($task)->at(now()->subHour()->format('Y-m-d H:i:s'))->create();
        });

        $this->artisan('nunez:dispatch-due')->assertSuccessful();
        $this->artisan('nunez:dispatch-due')->assertSuccessful();

        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertDatabaseHas('notification_deliveries', [
            'occurrence_id' => $occurrence->id,
            'user_id' => $alice->id,
            'account_id' => $this->accountOf($alice)->id,
            'kind' => 'end_prompt',
            'channel' => 'telegram',
        ]);
        $this->assertSame(OccurrenceState::AwaitingResponse, $this->withoutTenancy(fn () => $occurrence->fresh())?->state);
        Queue::assertPushed(SendCompletionPrompt::class, 1);
    }

    public function test_two_users_receive_separate_tenant_scoped_deliveries(): void
    {
        Queue::fake();
        foreach ([$this->alice(), $this->bob()] as $user) {
            $this->asTenant($user, function () use ($user): void {
                $task = Task::factory()->create(['owner_user_id' => $user->id]);
                TaskOccurrence::factory()->forTask($task)->at(now()->subHour()->format('Y-m-d H:i:s'))->create();
            });
        }

        $this->artisan('nunez:dispatch-due')->assertSuccessful();

        $this->assertDatabaseCount('notification_deliveries', 2);
        foreach ([$this->alice(), $this->bob()] as $user) {
            $this->assertDatabaseHas('notification_deliveries', [
                'user_id' => $user->id, 'account_id' => $this->accountOf($user)->id,
            ]);
        }
        Queue::assertPushed(SendCompletionPrompt::class, 2);
    }

    public function test_delivery_job_sends_once_and_skips_a_cancelled_occurrence(): void
    {
        Queue::fake();
        $alice = $this->alice();
        $occurrence = $this->asTenant($alice, function () {
            $task = Task::factory()->create();

            return TaskOccurrence::factory()->forTask($task)->at(now()->subHour()->format('Y-m-d H:i:s'))->create();
        });
        $this->artisan('nunez:dispatch-due')->assertSuccessful();

        $channel = new RecordingChannel;
        $this->asTenant($alice, function () use ($alice, $channel): void {
            $delivery = NotificationDelivery::query()->sole();
            $job = new SendCompletionPrompt($delivery->id, $alice->id, $delivery->account_id);
            $job->handle($channel);
            $job->handle($channel);
        });
        $this->assertSame(1, $channel->sends);
        $this->assertDatabaseHas('notification_deliveries', ['occurrence_id' => $occurrence->id, 'status' => 'sent']);

        $this->asTenant($alice, fn () => app(CancelOccurrence::class)->handle($occurrence, $alice));
        $this->assertDatabaseHas('notification_deliveries', ['occurrence_id' => $occurrence->id, 'status' => 'sent']);
    }

    public function test_pending_delivery_is_cancelled_when_occurrence_changes(): void
    {
        Queue::fake();
        $alice = $this->alice();
        $occurrence = $this->asTenant($alice, function () {
            $task = Task::factory()->create();

            return TaskOccurrence::factory()->forTask($task)->at(now()->subHour()->format('Y-m-d H:i:s'))->create();
        });
        $this->artisan('nunez:dispatch-due')->assertSuccessful();
        $this->asTenant($alice, fn () => app(CancelOccurrence::class)->handle($occurrence, $alice));

        $this->assertDatabaseHas('notification_deliveries', ['occurrence_id' => $occurrence->id, 'status' => 'cancelled']);
    }

    public function test_stuck_sending_delivery_is_requeued_for_recovery(): void
    {
        Queue::fake();
        $alice = $this->alice();
        $this->asTenant($alice, function () {
            $task = Task::factory()->create();
            TaskOccurrence::factory()->forTask($task)->at(now()->subHour()->format('Y-m-d H:i:s'))->create();
        });
        $this->artisan('nunez:dispatch-due')->assertSuccessful();
        $delivery = $this->asTenant($alice, fn () => NotificationDelivery::query()->sole());
        $this->asTenant($alice, fn () => $delivery->forceFill(['status' => 'sending', 'updated_at' => now()->subMinutes(20)])->save());
        Queue::fake();

        $this->artisan('nunez:recover-deliveries')->assertSuccessful();

        $this->assertDatabaseHas('notification_deliveries', ['id' => $delivery->id, 'status' => 'pending']);
        Queue::assertPushed(SendCompletionPrompt::class, 1);
    }
}

class RecordingChannel implements NotificationChannel
{
    public int $sends = 0;

    public function availableFor(User $user): bool
    {
        return true;
    }

    public function sendCompletionPrompt(User $user, TaskOccurrence $occurrence, NotificationDelivery $delivery): DeliveryResult
    {
        $this->sends++;

        return DeliveryResult::sent('test-message');
    }

    public function sendDailyReview(User $user, DailyReview $review, NotificationDelivery $delivery): DeliveryResult
    {
        return DeliveryResult::skipped();
    }
}
