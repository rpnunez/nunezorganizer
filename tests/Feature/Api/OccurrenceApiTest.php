<?php

namespace Tests\Feature\Api;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\User;
use App\Models\UserPreference;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * #9 API: schedule/reschedule/cancel blocks, record responses, complete, correct.
 */
class OccurrenceApiTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 15:20:00', 'UTC'));
    }

    private function actAs(User $user): void
    {
        Sanctum::actingAs($user, ['tasks:read', 'tasks:write']);
    }

    /**
     * @return array{0: Task, 1: TaskOccurrence}
     */
    private function block(User $user, string $startUtc = '2026-11-09 15:00:00'): array
    {
        return $this->asTenant($user, function () use ($startUtc) {
            $task = Task::factory()->create();

            return [$task, TaskOccurrence::factory()->forTask($task)->at($startUtc)->awaitingResponse()->create()];
        });
    }

    public function test_yes_response_completes_and_reports_punctuality(): void
    {
        [$task, $occurrence] = $this->block($this->alice());
        $this->actAs($this->alice());

        $this->postJson("/api/v1/occurrences/{$occurrence->id}/responses", ['response' => 'yes'])
            ->assertCreated()
            ->assertJsonPath('response.response', 'yes')
            ->assertJsonPath('response.punctuality', 'within_grace')
            ->assertJsonPath('task.status', 'completed')
            ->assertJsonPath('occurrence.state', 'finished')
            ->assertJsonPath('replayed', false);
    }

    public function test_no_response_keeps_task_open(): void
    {
        [$task, $occurrence] = $this->block($this->alice());
        $this->actAs($this->alice());

        $this->postJson("/api/v1/occurrences/{$occurrence->id}/responses", ['response' => 'no'])
            ->assertCreated()
            ->assertJsonPath('task.status', 'open')
            ->assertJsonPath('occurrence.state', 'missed');
    }

    public function test_retry_with_same_idempotency_key_applies_once(): void
    {
        [$task, $occurrence] = $this->block($this->alice());
        $this->actAs($this->alice());
        $headers = ['Idempotency-Key' => 'resp-1'];

        $this->postJson("/api/v1/occurrences/{$occurrence->id}/responses", ['response' => 'yes'], $headers)->assertCreated();
        $this->postJson("/api/v1/occurrences/{$occurrence->id}/responses", ['response' => 'yes'], $headers)->assertSuccessful();

        $this->assertSame(1, $this->asTenant($this->alice(), fn () => $task->responses()->count()));
    }

    public function test_conflicting_second_response_returns_409(): void
    {
        [, $occurrence] = $this->block($this->alice());
        $this->actAs($this->alice());

        $this->postJson("/api/v1/occurrences/{$occurrence->id}/responses", ['response' => 'yes'])->assertCreated();
        $this->postJson("/api/v1/occurrences/{$occurrence->id}/responses", ['response' => 'no'])->assertStatus(409);
    }

    public function test_completed_at_without_offset_is_read_in_the_users_timezone(): void
    {
        $this->withoutTenancy(fn () => UserPreference::where('user_id', $this->alice()->id)->update(['timezone' => 'America/New_York']));
        [$task, $occurrence] = $this->block($this->alice());
        $this->actAs($this->alice());

        // 10:10 New York (EST) = 15:10Z, before the 15:15Z planned end -> on time.
        $this->postJson("/api/v1/occurrences/{$occurrence->id}/responses", ['response' => 'yes', 'completed_at' => '2026-11-09 10:10'])
            ->assertCreated()
            ->assertJsonPath('response.completed_at', '2026-11-09T15:10:00Z')
            ->assertJsonPath('response.punctuality', 'on_time');
    }

    public function test_complete_endpoint_uses_the_current_block_for_punctuality(): void
    {
        [$task] = $this->block($this->alice());
        $this->actAs($this->alice());

        $this->postJson("/api/v1/tasks/{$task->id}/complete")
            ->assertCreated()
            ->assertJsonPath('task.status', 'completed')
            ->assertJsonPath('response.punctuality', 'within_grace');
    }

    public function test_complete_without_any_block_has_no_punctuality(): void
    {
        $task = $this->asTenant($this->alice(), fn () => Task::factory()->create());
        $this->actAs($this->alice());

        $this->postJson("/api/v1/tasks/{$task->id}/complete")
            ->assertCreated()
            ->assertJsonPath('response.punctuality', null);
    }

    public function test_schedule_another_attempt_of_the_same_task(): void
    {
        [$task] = $this->block($this->alice());
        $this->actAs($this->alice());

        $this->postJson("/api/v1/tasks/{$task->id}/occurrences", [
            'starts_at' => '2026-11-10 19:00', 'ends_at' => '2026-11-10 19:15', 'timezone' => 'America/New_York',
        ])->assertCreated()->assertJsonPath('data.task_id', $task->id)->assertJsonPath('data.starts_at', '2026-11-11T00:00:00Z');

        $this->assertSame(1, $this->asTenant($this->alice(), fn () => Task::count()), 'No duplicate task.');
        $this->assertSame(2, $this->asTenant($this->alice(), fn () => $task->occurrences()->count()));
    }

    public function test_reschedule_and_cancel_endpoints(): void
    {
        [, $upcoming] = $this->block($this->alice(), '2026-11-09 18:00:00');
        $this->withoutTenancy(fn () => TaskOccurrence::whereKey($upcoming->id)->update(['state' => 'scheduled']));
        $this->actAs($this->alice());

        $this->patchJson("/api/v1/occurrences/{$upcoming->id}", [
            'starts_at' => '2026-11-09T20:00:00Z', 'ends_at' => '2026-11-09T20:30:00Z', 'timezone' => 'UTC',
        ])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.starts_at', '2026-11-09T20:00:00Z');

        $this->postJson("/api/v1/occurrences/{$upcoming->id}/cancel")->assertOk()->assertJsonPath('data.state', 'cancelled');
        $this->patchJson("/api/v1/occurrences/{$upcoming->id}", [
            'starts_at' => '2026-11-09T21:00:00Z', 'ends_at' => '2026-11-09T21:30:00Z', 'timezone' => 'UTC',
        ])->assertStatus(409);
    }

    public function test_completion_time_correction_endpoint(): void
    {
        [$task, $occurrence] = $this->block($this->alice());
        $this->actAs($this->alice());
        $this->postJson("/api/v1/occurrences/{$occurrence->id}/responses", ['response' => 'yes'])->assertCreated();

        $this->postJson("/api/v1/tasks/{$task->id}/completion-time", ['completed_at' => '2026-11-09T15:05:00Z'])
            ->assertOk()
            ->assertJsonPath('response.punctuality', 'on_time')
            ->assertJsonPath('task.completed_at', '2026-11-09T15:05:00Z');
    }

    public function test_foreign_blocks_are_404_and_untouched(): void
    {
        [$bobTask, $bobBlock] = $this->block($this->bob());
        $this->actAs($this->alice());

        $this->postJson("/api/v1/occurrences/{$bobBlock->id}/responses", ['response' => 'yes'])->assertNotFound();
        $this->postJson("/api/v1/tasks/{$bobTask->id}/complete")->assertNotFound();
        $this->postJson("/api/v1/tasks/{$bobTask->id}/occurrences", ['starts_at' => '2026-11-10 10:00', 'ends_at' => '2026-11-10 10:15', 'timezone' => 'UTC'])->assertNotFound();

        $this->assertSame(TaskStatus::Open, $this->withoutTenancy(fn () => $bobTask->fresh())?->status);
    }

    public function test_complete_rejects_an_occurrence_id_from_another_task(): void
    {
        [$task] = $this->block($this->alice());
        [, $otherBlock] = $this->block($this->alice());
        $this->actAs($this->alice());

        $this->postJson("/api/v1/tasks/{$task->id}/complete", ['occurrence_id' => $otherBlock->id])->assertNotFound();
    }

    public function test_validation(): void
    {
        [, $occurrence] = $this->block($this->alice());
        $this->actAs($this->alice());

        $this->postJson("/api/v1/occurrences/{$occurrence->id}/responses", ['response' => 'maybe'])->assertUnprocessable();
        $this->postJson("/api/v1/occurrences/{$occurrence->id}/responses", ['response' => 'no', 'completed_at' => '2026-11-09T15:00:00Z'])->assertUnprocessable();
        $this->postJson("/api/v1/occurrences/{$occurrence->id}/responses", ['response' => 'yes', 'completed_at' => '2026-11-10T15:00:00Z'])->assertUnprocessable();
    }
}
