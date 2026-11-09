<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\WeeklyStats;
use App\Domain\Scheduling\Actions\RecordResponse;
use App\Enums\ResponseChannel;
use App\Enums\ResponseType;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class WeeklyStatsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_weekly_counts_never_treat_silence_as_done_and_exclude_other_users(): void
    {
        $alice = $this->alice();
        $bob = $this->bob();
        $this->travelTo('2026-11-10 20:00:00 UTC');
        $this->asTenant($alice, function () use ($alice): void {
            UserPreference::where('user_id', $alice->id)->firstOrFail()->update(['timezone' => 'America/New_York']);
            $done = Task::factory()->create(['title' => 'Alice done']);
            $block = TaskOccurrence::factory()->forTask($done)->at('2026-11-09 15:00:00')->create();
            app(RecordResponse::class)->handle($done, $block, ResponseType::Yes, $alice,
                ResponseChannel::Web, 'weekly-alice-done');
            TaskOccurrence::factory()->forTask(Task::factory()->create(['title' => 'Alice missed']))
                ->at('2026-11-09 16:00:00')->missed()->create();
            TaskOccurrence::factory()->forTask(Task::factory()->create(['title' => 'Alice silent']))
                ->at('2026-11-09 17:00:00')->create(['state' => 'unanswered']);
            TaskOccurrence::factory()->forTask(Task::factory()->create(['title' => 'Alice pending']))
                ->at('2026-11-11 17:00:00')->create();
        });
        $this->asTenant($bob, fn () => TaskOccurrence::factory()
            ->forTask(Task::factory()->create(['title' => 'Bob private']))->at('2026-11-09 15:00:00')->create());

        $stats = $this->asTenant($alice, fn () => app(WeeklyStats::class)->forUser($alice, '2026-11-09', 'America/New_York'));
        $this->assertSame(4, $stats['planned']);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(1, $stats['late']);
        $this->assertSame(1, $stats['missed']);
        $this->assertSame(1, $stats['unanswered']);
        $this->assertSame(1, $stats['pending']);

        Sanctum::actingAs($alice, ['tasks:read']);
        $this->getJson('/api/v1/progress/weekly?week=2026-11-09')->assertOk()->assertJsonPath('planned', 4);
        $this->actingAs($alice)->get('/progress')->assertOk()->assertSee('4 planned blocks');
        $this->actingAs($alice)->get('/history')->assertOk()->assertSee('Alice done')->assertDontSee('Bob private');
    }

    public function test_sunday_late_evening_uses_local_week_boundary(): void
    {
        $alice = $this->alice();
        $this->asTenant($alice, function (): void {
            $task = Task::factory()->create();
            TaskOccurrence::factory()->forTask($task)->at('2026-11-16 04:30:00')->create();
        });

        $newYork = $this->asTenant($alice, fn () => app(WeeklyStats::class)->forUser($alice, '2026-11-09', 'America/New_York'));
        $utc = $this->asTenant($alice, fn () => app(WeeklyStats::class)->forUser($alice, '2026-11-09', 'UTC'));
        $this->assertSame(1, $newYork['planned']);
        $this->assertSame(0, $utc['planned']);
    }
}
