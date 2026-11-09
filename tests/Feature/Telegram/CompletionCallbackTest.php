<?php

namespace Tests\Feature\Telegram;

use App\Domain\Notifications\Jobs\SendCompletionPrompt;
use App\Enums\TaskStatus;
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

class CompletionCallbackTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.telegram.webhook_secret', 'test_webhook_secret');
        config()->set('services.telegram.route_key', 'test_route_key');
        config()->set('services.telegram.token', '123456:TEST_TOKEN_NOT_REAL');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]])]);
    }

    public function test_yes_callback_completes_once_and_replay_does_not_duplicate_response(): void
    {
        Queue::fake();
        $alice = $this->alice();
        [$task, $occurrence] = $this->asTenant($alice, function () use ($alice) {
            TelegramLink::query()->create(['user_id' => $alice->id, 'telegram_user_id' => 111,
                'telegram_chat_id' => 111, 'status' => 'active', 'linked_at' => now()]);
            $task = Task::factory()->create();

            return [$task, TaskOccurrence::factory()->forTask($task)->at(now()->subHour()->format('Y-m-d H:i:s'))->create()];
        });

        $this->artisan('nunez:dispatch-due')->assertSuccessful();
        $this->asTenant($alice, function () use ($alice): void {
            $delivery = NotificationDelivery::query()->sole();
            (new SendCompletionPrompt($delivery->id, $alice->id, $delivery->account_id))->handle(app(TelegramChannel::class));
        });

        $token = null;
        $noToken = null;
        Http::assertSent(function ($request) use (&$token, &$noToken): bool {
            if (! str_ends_with($request->url(), '/sendMessage')) {
                return false;
            }
            $token = $request['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null;
            $noToken = $request['reply_markup']['inline_keyboard'][0][1]['callback_data'] ?? null;

            return is_string($token);
        });
        $this->assertNotNull($token);
        $this->assertNotNull($noToken);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test_webhook_secret')
            ->postJson('/webhooks/telegram/test_route_key', [
                'update_id' => 19,
                'callback_query' => ['id' => 'foreign', 'data' => $token,
                    'from' => ['id' => 222], 'message' => ['chat' => ['id' => 222, 'type' => 'private'], 'message_id' => 99]],
            ])->assertOk();
        $this->assertSame(TaskStatus::Open, $this->withoutTenancy(fn () => $task->fresh())?->status);

        foreach ([20, 21] as $updateId) {
            $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test_webhook_secret')
                ->postJson('/webhooks/telegram/test_route_key', [
                    'update_id' => $updateId,
                    'callback_query' => ['id' => 'callback-'.$updateId, 'data' => $token,
                        'from' => ['id' => 111], 'message' => ['chat' => ['id' => 111, 'type' => 'private'], 'message_id' => 99]],
                ])->assertOk();
        }

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test_webhook_secret')
            ->postJson('/webhooks/telegram/test_route_key', [
                'update_id' => 22,
                'callback_query' => ['id' => 'late-no', 'data' => $noToken,
                    'from' => ['id' => 111], 'message' => ['chat' => ['id' => 111, 'type' => 'private'], 'message_id' => 99]],
            ])->assertOk();

        $this->assertSame(TaskStatus::Completed, $this->withoutTenancy(fn () => $task->fresh())?->status);
        $this->assertSame(1, $this->asTenant($alice, fn () => $task->responses()->count()));
        $this->assertDatabaseHas('notification_deliveries', ['occurrence_id' => $occurrence->id, 'status' => 'sent']);
    }

    public function test_no_callback_leaves_task_open_and_marks_attempt_missed(): void
    {
        Queue::fake();
        $alice = $this->alice();
        [$task, $occurrence] = $this->asTenant($alice, function () use ($alice) {
            TelegramLink::query()->create(['user_id' => $alice->id, 'telegram_user_id' => 111,
                'telegram_chat_id' => 111, 'status' => 'active', 'linked_at' => now()]);
            $task = Task::factory()->create();

            return [$task, TaskOccurrence::factory()->forTask($task)->at(now()->subHour()->format('Y-m-d H:i:s'))->create()];
        });

        $this->artisan('nunez:dispatch-due')->assertSuccessful();
        $this->asTenant($alice, function () use ($alice): void {
            $delivery = NotificationDelivery::query()->sole();
            (new SendCompletionPrompt($delivery->id, $alice->id, $delivery->account_id))->handle(app(TelegramChannel::class));
        });

        $token = null;
        Http::assertSent(function ($request) use (&$token): bool {
            if (! str_ends_with($request->url(), '/sendMessage')) {
                return false;
            }
            $token = $request['reply_markup']['inline_keyboard'][0][1]['callback_data'] ?? null;

            return is_string($token);
        });
        $this->assertNotNull($token);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test_webhook_secret')
            ->postJson('/webhooks/telegram/test_route_key', [
                'update_id' => 30,
                'callback_query' => ['id' => 'no', 'data' => $token,
                    'from' => ['id' => 111], 'message' => ['chat' => ['id' => 111, 'type' => 'private'], 'message_id' => 99]],
            ])->assertOk();

        $this->assertSame(TaskStatus::Open, $this->withoutTenancy(fn () => $task->fresh())?->status);
        $this->assertSame('missed', $this->withoutTenancy(fn () => $occurrence->fresh())?->state->value);
        $this->assertSame(1, $this->asTenant($alice, fn () => $task->responses()->count()));
    }
}
