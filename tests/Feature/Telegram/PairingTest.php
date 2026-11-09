<?php

namespace Tests\Feature\Telegram;

use App\Integrations\Telegram\ConfirmPairing;
use App\Integrations\Telegram\CreatePairingCode;
use App\Integrations\Telegram\TelegramChannel;
use App\Integrations\Telegram\UnlinkTelegram;
use App\Models\PairingCode;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\TelegramLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PairingTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.telegram.webhook_secret', 'test_webhook_secret');
        config()->set('services.telegram.route_key', 'test_route_key');
        config()->set('services.telegram.token', '123456:TEST_TOKEN_NOT_REAL');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    public function test_webhook_rejects_missing_or_invalid_secret(): void
    {
        $url = '/webhooks/telegram/test_route_key';
        $this->postJson($url, ['update_id' => 1])->assertForbidden();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong')
            ->postJson($url, ['update_id' => 1])->assertForbidden();
        $this->assertDatabaseCount('pairing_codes', 0);
    }

    public function test_private_start_requires_account_owner_confirmation(): void
    {
        $alice = $this->alice();
        $bob = $this->bob();
        $code = $this->asTenant($alice, fn () => app(CreatePairingCode::class)->handle($alice));

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test_webhook_secret')
            ->postJson('/webhooks/telegram/test_route_key', [
                'update_id' => 2,
                'message' => ['text' => '/start '.$code, 'from' => ['id' => 111], 'chat' => ['id' => 111, 'type' => 'private']],
            ])->assertOk();

        $this->assertDatabaseCount('telegram_links', 0);
        $this->expectException(ValidationException::class);
        try {
            $this->asTenant($bob, fn () => app(ConfirmPairing::class)->handle($bob, $code));
        } finally {
            $this->assertDatabaseCount('telegram_links', 0);
            $this->asTenant($alice, fn () => app(ConfirmPairing::class)->handle($alice, $code));
            $this->assertSame(111, $this->withoutTenancy(fn () => TelegramLink::where('user_id', $alice->id)->firstOrFail()->telegram_user_id));
        }
    }

    public function test_expired_code_and_group_chat_cannot_stage_pairing(): void
    {
        $alice = $this->alice();
        $code = $this->asTenant($alice, fn () => app(CreatePairingCode::class)->handle($alice));
        $before = $this->withoutTenancy(fn () => PairingCode::where('user_id', $alice->id)->firstOrFail());
        $this->assertGreaterThan(500, now()->diffInSeconds($before->expires_at));
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test_webhook_secret')
            ->postJson('/webhooks/telegram/test_route_key', [
                'update_id' => 3,
                'message' => ['text' => '/start '.$code, 'from' => ['id' => 111], 'chat' => ['id' => -1, 'type' => 'group']],
            ])->assertOk();
        $this->assertNull($this->withoutTenancy(fn () => PairingCode::where('user_id', $alice->id)->firstOrFail()->claimed_telegram_user_id));

        $this->travel(11)->minutes();
        $this->postJson('/webhooks/telegram/test_route_key', [
            'update_id' => 4,
            'message' => ['text' => '/start '.$code, 'from' => ['id' => 111], 'chat' => ['id' => 111, 'type' => 'private']],
        ])->assertOk();
        $this->assertNull($this->withoutTenancy(fn () => PairingCode::where('user_id', $alice->id)->firstOrFail()->claimed_telegram_user_id));
    }

    public function test_cannot_create_a_foreign_users_pairing_code(): void
    {
        $alice = $this->alice();
        $bob = $this->bob();
        $this->expectException(HttpException::class);
        $this->asTenant($alice, fn () => app(CreatePairingCode::class)->handle($bob));
    }

    public function test_confirmed_link_can_be_revoked_and_code_cannot_be_reused(): void
    {
        $alice = $this->alice();
        $code = $this->asTenant($alice, fn () => app(CreatePairingCode::class)->handle($alice));
        $before = $this->withoutTenancy(fn () => PairingCode::where('user_id', $alice->id)->firstOrFail());
        $this->assertGreaterThan(500, now()->diffInSeconds($before->expires_at));
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test_webhook_secret')
            ->postJson('/webhooks/telegram/test_route_key', [
                'update_id' => 10,
                'message' => ['text' => '/start '.$code, 'from' => ['id' => 111], 'chat' => ['id' => 111, 'type' => 'private']],
            ])->assertOk();

        $staged = $this->withoutTenancy(fn () => PairingCode::where('user_id', $alice->id)->firstOrFail());
        $this->assertSame(111, $staged->claimed_telegram_user_id);
        $this->assertSame(111, $staged->claimed_telegram_chat_id);
        $this->assertNull($staged->used_at);
        $this->assertSame($before->expires_at->toIso8601String(), $staged->expires_at->toIso8601String(), 'Expiry changed while staging');
        $this->assertFalse($staged->expires_at->isPast(), 'now '.now()->toIso8601String().' expires '.$staged->expires_at->toIso8601String());

        $this->asTenant($alice, function () use ($alice, $code): void {
            app(ConfirmPairing::class)->handle($alice, $code);
            $this->assertTrue(app(TelegramChannel::class)->availableFor($alice));
            app(UnlinkTelegram::class)->handle($alice);
            $this->assertFalse(app(TelegramChannel::class)->availableFor($alice));
        });

        $this->expectException(ValidationException::class);
        $this->asTenant($alice, fn () => app(ConfirmPairing::class)->handle($alice, $code));
    }

    public function test_telegram_settings_page_renders_for_the_account_owner(): void
    {
        $this->actingAs($this->alice())->get(route('telegram.edit'))
            ->assertOk()->assertSee('Generate pairing code');
    }

    public function test_private_bot_commands_show_today_and_unlink(): void
    {
        $alice = $this->alice();
        $this->asTenant($alice, function () use ($alice): void {
            TelegramLink::query()->create(['user_id' => $alice->id, 'telegram_user_id' => 111,
                'telegram_chat_id' => 111, 'status' => 'active', 'linked_at' => now()]);
            $task = Task::factory()->create(['title' => 'Bot summary task']);
            TaskOccurrence::factory()->forTask($task)->at(now()->addHour()->format('Y-m-d H:i:s'))->create();
        });

        foreach (['/today', '/backlog', '/unlink'] as $index => $command) {
            $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test_webhook_secret')
                ->postJson('/webhooks/telegram/test_route_key', [
                    'update_id' => 100 + $index,
                    'message' => ['text' => $command, 'from' => ['id' => 111],
                        'chat' => ['id' => 111, 'type' => 'private']],
                ])->assertOk();
        }

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Bot summary task'));
        $this->assertDatabaseHas('telegram_links', ['user_id' => $alice->id, 'status' => 'revoked']);
    }
}
