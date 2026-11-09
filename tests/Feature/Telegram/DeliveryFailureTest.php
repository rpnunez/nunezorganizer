<?php

namespace Tests\Feature\Telegram;

use App\Domain\Notifications\Jobs\SendCompletionPrompt;
use App\Integrations\Telegram\TelegramChannel;
use App\Models\NotificationDelivery;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\TelegramLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class DeliveryFailureTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private function pendingPrompt(): array
    {
        Queue::fake();
        config()->set('services.telegram.token', '123456:TEST_TOKEN_NOT_REAL');
        $alice = $this->alice();
        $task = $this->asTenant($alice, function () use ($alice) {
            TelegramLink::query()->create(['user_id' => $alice->id, 'telegram_user_id' => 111,
                'telegram_chat_id' => 111, 'status' => 'active', 'linked_at' => now()]);
            $task = Task::factory()->create();
            TaskOccurrence::factory()->forTask($task)->at(now()->subHour()->format('Y-m-d H:i:s'))->create();

            return $task;
        });
        $this->artisan('nunez:dispatch-due')->assertSuccessful();

        return [$alice, $task];
    }

    public function test_rate_limit_keeps_one_pending_delivery_for_retry(): void
    {
        [$alice, $task] = $this->pendingPrompt();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'parameters' => ['retry_after' => 12]], 429)]);

        $this->asTenant($alice, function () use ($alice): void {
            $delivery = NotificationDelivery::query()->sole();
            (new SendCompletionPrompt($delivery->id, $alice->id, $delivery->account_id))->handle(app(TelegramChannel::class));
        });

        $this->assertDatabaseHas('notification_deliveries', ['user_id' => $alice->id, 'status' => 'pending', 'last_error_code' => 'rate_limited']);
        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertSame('open', $this->withoutTenancy(fn () => $task->fresh())?->status->value);
    }

    public function test_blocked_bot_marks_link_and_delivery_without_retrying(): void
    {
        [$alice] = $this->pendingPrompt();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 403)]);

        $this->asTenant($alice, function () use ($alice): void {
            $delivery = NotificationDelivery::query()->sole();
            (new SendCompletionPrompt($delivery->id, $alice->id, $delivery->account_id))->handle(app(TelegramChannel::class));
        });

        $this->assertDatabaseHas('notification_deliveries', ['user_id' => $alice->id, 'status' => 'failed', 'last_error_code' => 'blocked']);
        $this->assertDatabaseHas('telegram_links', ['user_id' => $alice->id, 'status' => 'blocked']);
    }
}
