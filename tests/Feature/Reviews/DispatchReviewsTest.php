<?php

namespace Tests\Feature\Reviews;

use App\Domain\Notifications\Jobs\SendDailyReview;
use App\Integrations\Telegram\TelegramChannel;
use App\Models\DailyReview;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\TelegramLink;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class DispatchReviewsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_local_review_runs_once_per_kind_even_when_dispatcher_repeats(): void
    {
        Queue::fake();
        $alice = $this->alice();
        $this->asTenant($alice, function () use ($alice): void {
            UserPreference::where('user_id', $alice->id)->firstOrFail()->update([
                'timezone' => 'America/New_York',
                'review_times' => ['morning' => '10:45', 'afternoon' => '14:00', 'evening' => '20:00'],
            ]);
            TelegramLink::create(['user_id' => $alice->id, 'telegram_user_id' => 111,
                'telegram_chat_id' => 111, 'status' => 'active', 'linked_at' => now()]);
        });

        $this->travelTo('2026-11-09 15:44:00 UTC');
        $this->artisan('nunez:dispatch-reviews')->assertSuccessful();
        $this->assertDatabaseCount('daily_reviews', 0);

        $this->travelTo('2026-11-09 15:45:00 UTC');
        $this->artisan('nunez:dispatch-reviews')->assertSuccessful();
        $this->artisan('nunez:dispatch-reviews')->assertSuccessful();
        $this->assertDatabaseCount('daily_reviews', 1);
        $this->assertSame('morning', $this->asTenant($alice, fn () => DailyReview::sole()->kind));
    }

    public function test_spring_gap_and_repeated_fall_hour_send_once(): void
    {
        Queue::fake();
        $alice = $this->alice();
        $this->asTenant($alice, function () use ($alice): void {
            UserPreference::where('user_id', $alice->id)->firstOrFail()->update([
                'timezone' => 'America/New_York',
                'review_times' => ['morning' => '02:30', 'afternoon' => null, 'evening' => null],
            ]);
        });

        $this->travelTo('2027-03-14 06:59:00 UTC');
        $this->artisan('nunez:dispatch-reviews')->assertSuccessful();
        $this->assertDatabaseCount('daily_reviews', 0);
        $this->travelTo('2027-03-14 07:00:00 UTC');
        $this->artisan('nunez:dispatch-reviews')->assertSuccessful();
        $this->assertDatabaseCount('daily_reviews', 1);

        $this->asTenant($alice, fn () => UserPreference::where('user_id', $alice->id)->firstOrFail()->update([
            'review_times' => ['morning' => '01:30', 'afternoon' => null, 'evening' => null],
        ]));
        $this->travelTo('2027-11-07 05:30:00 UTC');
        $this->artisan('nunez:dispatch-reviews')->assertSuccessful();
        $this->travelTo('2027-11-07 06:30:00 UTC');
        $this->artisan('nunez:dispatch-reviews')->assertSuccessful();
        $this->assertDatabaseCount('daily_reviews', 2);
    }

    public function test_quiet_hours_defer_review_until_allowed_time(): void
    {
        Queue::fake();
        $alice = $this->alice();
        $this->asTenant($alice, fn () => UserPreference::where('user_id', $alice->id)->firstOrFail()->update([
            'timezone' => 'UTC', 'review_times' => ['morning' => '10:45', 'afternoon' => null, 'evening' => null],
            'quiet_hours' => ['start' => '10:00', 'end' => '11:00'],
        ]));
        $this->travelTo('2026-11-09 10:45:00 UTC');
        $this->artisan('nunez:dispatch-reviews')->assertSuccessful();
        $this->assertDatabaseCount('daily_reviews', 0);
        $this->travelTo('2026-11-09 11:00:00 UTC');
        $this->artisan('nunez:dispatch-reviews')->assertSuccessful();
        $this->assertDatabaseCount('daily_reviews', 1);
    }

    public function test_two_users_receive_their_review_at_different_utc_instants(): void
    {
        Queue::fake();
        $alice = $this->alice();
        $bob = $this->bob();
        foreach ([[$alice, 'America/New_York'], [$bob, 'UTC']] as [$user, $timezone]) {
            $this->asTenant($user, fn () => UserPreference::where('user_id', $user->id)->firstOrFail()->update([
                'timezone' => $timezone,
                'review_times' => ['morning' => '10:45', 'afternoon' => null, 'evening' => null],
            ]));
        }

        $this->travelTo('2026-11-09 10:45:00 UTC');
        $this->artisan('nunez:dispatch-reviews')->assertSuccessful();
        $this->assertDatabaseHas('daily_reviews', ['user_id' => $bob->id, 'kind' => 'morning']);
        $this->assertDatabaseMissing('daily_reviews', ['user_id' => $alice->id, 'kind' => 'morning']);
        $this->travelTo('2026-11-09 15:45:00 UTC');
        $this->artisan('nunez:dispatch-reviews')->assertSuccessful();
        $this->assertDatabaseHas('daily_reviews', ['user_id' => $alice->id, 'kind' => 'morning']);
        $this->assertDatabaseCount('daily_reviews', 2);
    }

    public function test_review_delivery_and_api_are_scoped_to_owner(): void
    {
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 20]])]);
        config()->set('services.telegram.token', '123456:TEST_TOKEN_NOT_REAL');
        $alice = $this->alice();
        $bob = $this->bob();
        foreach ([[$alice, 111], [$bob, 222]] as [$user, $chat]) {
            $this->asTenant($user, function () use ($user, $chat): void {
                UserPreference::where('user_id', $user->id)->firstOrFail()->update([
                    'timezone' => 'UTC',
                    'review_times' => ['morning' => '10:45', 'afternoon' => null, 'evening' => null],
                ]);
                TelegramLink::create(['user_id' => $user->id, 'telegram_user_id' => $chat,
                    'telegram_chat_id' => $chat, 'status' => 'active', 'linked_at' => now()]);
                $task = Task::factory()->create(['title' => $chat === 111 ? 'Alice private plan' : 'Bob private plan']);
                TaskOccurrence::factory()->forTask($task)->at('2026-11-09 12:00:00')->create();
            });
        }
        $this->travelTo('2026-11-09 10:45:00 UTC');
        $this->artisan('nunez:dispatch-reviews')->assertSuccessful();
        $this->assertDatabaseCount('daily_reviews', 2);

        $this->asTenant($alice, function () use ($alice): void {
            $review = DailyReview::where('user_id', $alice->id)->sole();
            (new SendDailyReview($review->delivery_id, $alice->id, $review->account_id))
                ->handle(app(TelegramChannel::class));
        });
        $this->assertDatabaseHas('daily_reviews', ['user_id' => $alice->id, 'status' => 'sent']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === 111 && str_contains((string) $request['text'], 'Alice private plan')
            && ! str_contains((string) $request['text'], 'Bob private plan'));
        Sanctum::actingAs($alice, ['tasks:read']);
        $this->getJson('/api/v1/reviews/today')->assertOk()->assertJsonCount(1, 'reviews');
        Sanctum::actingAs($bob, ['tasks:read']);
        $this->getJson('/api/v1/reviews/today')->assertOk()->assertJsonCount(1, 'reviews')
            ->assertJsonMissing(['id' => $this->asTenant($alice, fn () => DailyReview::where('user_id', $alice->id)->sole()->id)]);
    }
}
