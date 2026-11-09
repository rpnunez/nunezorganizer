<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Accounts\TenantContext;
use App\Domain\Scheduling\Queries\BacklogQuery;
use App\Domain\Scheduling\Queries\LocalDay;
use App\Domain\Scheduling\Queries\TodayQuery;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\OccurrenceState;
use App\Models\AccountMembership;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\User;
use App\Models\UserPreference;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * #10 acceptance: carryover is a query over unresolved attempts, never a copy.
 * Dates: Mon 2026-11-09, Tue 10, Wed 11, Thu 12, Fri 13, Mon 16.
 */
class CarryoverTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private function setTimezone(User $user, string $tz): void
    {
        $this->withoutTenancy(fn () => UserPreference::where('user_id', $user->id)->update(['timezone' => $tz]));
    }

    private function backlog(User $user, string $date): TestResponse
    {
        Sanctum::actingAs($user, ['tasks:read', 'tasks:write']);

        return $this->getJson("/api/v1/backlog?date={$date}")->assertOk();
    }

    /**
     * @return array{0: Task, 1: TaskOccurrence}
     */
    private function missedBlock(User $user, string $startUtc, OccurrenceState $state = OccurrenceState::Missed, ?Task $task = null): array
    {
        return $this->asTenant($user, function () use ($startUtc, $state, $task) {
            $task ??= Task::factory()->create(['title' => 'Organize my desk']);

            return [$task, TaskOccurrence::factory()->forTask($task)->at($startUtc)->state(['state' => $state])->create()];
        });
    }

    public function test_t11_missed_monday_task_appears_once_on_following_days_with_history(): void
    {
        $alice = $this->alice();
        [$task] = $this->missedBlock($alice, '2026-11-09 15:00:00');

        foreach (['2026-11-10', '2026-11-11', '2026-11-12'] as $date) {
            $this->backlog($alice, $date)
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.task.id', $task->id)
                ->assertJsonPath('data.0.attempt_count', 1)
                ->assertJsonPath('data.0.overdue_since', '2026-11-09T15:15:00Z')
                ->assertJsonPath('data.0.attempts.0.state', 'missed');
        }

        // Not yet backlog on the day of the attempt itself (it's "today"), never duplicated.
        $this->backlog($alice, '2026-11-09')->assertJsonCount(0, 'data');
        $this->assertSame(1, $this->asTenant($alice, fn () => Task::count()));
    }

    public function test_weekend_gap_friday_miss_still_shows_on_monday(): void
    {
        $alice = $this->alice();
        [$task] = $this->missedBlock($alice, '2026-11-13 19:00:00', OccurrenceState::Unanswered);

        $this->backlog($alice, '2026-11-16')->assertJsonCount(1, 'data')->assertJsonPath('data.0.task.id', $task->id);
    }

    public function test_repeated_misses_of_one_task_are_one_backlog_item_with_full_history(): void
    {
        $alice = $this->alice();
        [$task] = $this->missedBlock($alice, '2026-11-09 15:00:00');
        $this->missedBlock($alice, '2026-11-10 15:00:00', OccurrenceState::Unanswered, $task);
        $this->missedBlock($alice, '2026-11-11 15:00:00', OccurrenceState::AwaitingResponse, $task);

        $this->backlog($alice, '2026-11-12')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attempt_count', 3)
            ->assertJsonPath('data.0.overdue_since', '2026-11-09T15:15:00Z')
            ->assertJsonCount(3, 'data.0.attempts');
    }

    public function test_ended_block_that_was_never_prompted_is_backlog(): void
    {
        $alice = $this->alice();
        $this->missedBlock($alice, '2026-11-09 15:00:00', OccurrenceState::Scheduled);

        $this->backlog($alice, '2026-11-10')->assertJsonCount(1, 'data');
    }

    public function test_completed_cancelled_and_future_only_tasks_are_not_backlog(): void
    {
        $alice = $this->alice();
        $this->asTenant($alice, function () {
            $done = Task::factory()->completed()->create();
            TaskOccurrence::factory()->forTask($done)->at('2026-11-09 15:00:00')->state(['state' => OccurrenceState::Finished])->create();

            $cancelled = Task::factory()->cancelled()->create();
            TaskOccurrence::factory()->forTask($cancelled)->at('2026-11-09 15:00:00')->missed()->create();

            $future = Task::factory()->create();
            TaskOccurrence::factory()->forTask($future)->at('2026-11-12 15:00:00')->create();

            Task::factory()->create(); // never scheduled
        });

        $this->backlog($alice, '2026-11-10')->assertJsonCount(0, 'data');
    }

    public function test_t12_rescheduling_adds_an_attempt_to_the_same_task_without_duplicating_it(): void
    {
        $alice = $this->alice();
        [$task] = $this->missedBlock($alice, '2026-11-09 15:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-11 08:00:00', 'UTC'));

        Sanctum::actingAs($alice, ['tasks:read', 'tasks:write']);
        $this->postJson("/api/v1/tasks/{$task->id}/occurrences", [
            'starts_at' => '2026-11-11T19:00:00Z', 'ends_at' => '2026-11-11T19:15:00Z', 'timezone' => 'UTC',
        ])->assertCreated()->assertJsonPath('data.task_id', $task->id);

        $this->backlog($alice, '2026-11-11')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.task.id', $task->id)
            ->assertJsonPath('data.0.rescheduled', true)
            ->assertJsonPath('data.0.next_attempt_at', '2026-11-11T19:00:00Z')
            ->assertJsonCount(2, 'data.0.attempts');

        $this->assertSame(1, $this->asTenant($alice, fn () => Task::count()), 'Same logical task.');
    }

    public function test_completing_a_carried_over_task_removes_it_from_the_backlog(): void
    {
        $alice = $this->alice();
        [$task, $block] = $this->missedBlock($alice, '2026-11-09 15:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'));

        Sanctum::actingAs($alice, ['tasks:read', 'tasks:write']);
        $this->postJson("/api/v1/occurrences/{$block->id}/responses", ['response' => 'yes'])
            ->assertCreated()->assertJsonPath('response.punctuality', 'late');

        $this->backlog($alice, '2026-11-11')->assertJsonCount(0, 'data');
    }

    public function test_local_day_boundaries_follow_the_users_timezone(): void
    {
        $alice = $this->alice();
        $this->setTimezone($alice, 'America/New_York');

        // Mon 23:30–23:45 New York (EST) = Tue 04:30–04:45Z.
        $this->missedBlock($alice, '2026-11-10 04:30:00');

        $this->backlog($alice, '2026-11-09')->assertJsonCount(0, 'data');   // still Monday locally
        $this->backlog($alice, '2026-11-10')->assertJsonCount(1, 'data');   // carried into Tuesday
    }

    public function test_backlog_is_isolated_between_users(): void
    {
        [$aliceTask] = $this->missedBlock($this->alice(), '2026-11-09 15:00:00');
        $this->missedBlock($this->bob(), '2026-11-09 15:00:00');

        $this->backlog($this->alice(), '2026-11-10')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.task.id', $aliceTask->id);
    }

    public function test_only_the_owners_tasks_are_listed_even_inside_a_shared_account(): void
    {
        // Sharing is disabled in the MVP, but the schema allows members. Owner filtering
        // must hold on its own, not only because accounts are personal today.
        $alice = $this->alice();
        $this->missedBlock($alice, '2026-11-09 15:00:00');
        $carol = User::factory()->create();
        $aliceAccountId = $this->accountOf($alice)->id;
        AccountMembership::create(['account_id' => $aliceAccountId, 'user_id' => $carol->id, 'role' => MembershipRole::Member, 'status' => MembershipStatus::Active]);

        $day = LocalDay::of('2026-11-10', 'UTC');
        [$backlog, $today] = app(TenantContext::class)->runAs($carol, fn () => [
            app(BacklogQuery::class)->query($carol, $day)->count(),
            app(TodayQuery::class)->blocks($carol, LocalDay::of('2026-11-09', 'UTC'))->count(),
        ], $aliceAccountId);

        $this->assertSame(0, $backlog);
        $this->assertSame(0, $today);
    }

    public function test_backlog_paginates_and_is_never_silently_capped(): void
    {
        $alice = $this->alice();
        foreach (range(1, 5) as $i) {
            $this->missedBlock($alice, "2026-11-0{$i} 15:00:00");
        }

        Sanctum::actingAs($alice, ['tasks:read']);
        $this->getJson('/api/v1/backlog?date=2026-11-10&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('data.0.overdue_since', '2026-11-01T15:15:00Z');
    }

    public function test_today_lists_the_local_days_blocks_pending_answers_and_backlog_count(): void
    {
        $alice = $this->alice();
        $this->setTimezone($alice, 'America/New_York');
        $this->travelTo(CarbonImmutable::parse('2026-11-10 18:00:00', 'UTC')); // 13:00 New York

        $this->missedBlock($alice, '2026-11-09 15:00:00');                                       // backlog
        $this->missedBlock($alice, '2026-11-10 14:00:00', OccurrenceState::AwaitingResponse);    // today, pending
        $this->missedBlock($alice, '2026-11-11 00:00:00', OccurrenceState::Scheduled);           // 19:00 NY today
        $this->missedBlock($alice, '2026-11-11 06:00:00', OccurrenceState::Scheduled);           // tomorrow NY

        Sanctum::actingAs($alice, ['tasks:read']);
        $this->getJson('/api/v1/today')
            ->assertOk()
            ->assertJsonPath('date', '2026-11-10')
            ->assertJsonPath('timezone', 'America/New_York')
            ->assertJsonCount(2, 'blocks')
            // 00:00Z on the 11th is 19:00 on the 10th in New York, so it belongs to the user's "today".
            ->assertJsonPath('blocks.1.starts_at', '2026-11-11T00:00:00Z')
            ->assertJsonCount(1, 'pending_responses')
            ->assertJsonPath('backlog_count', 1);
    }

    public function test_endpoints_require_read_ability_and_validate_dates(): void
    {
        Sanctum::actingAs($this->alice(), ['tasks:write']);
        $this->getJson('/api/v1/backlog')->assertForbidden();
        $this->getJson('/api/v1/today')->assertForbidden();

        Sanctum::actingAs($this->alice(), ['tasks:read']);
        $this->getJson('/api/v1/backlog?date=11/10/2026')->assertUnprocessable();
    }
}
