<?php

namespace Tests\Feature\Tasks;

use App\Domain\Accounts\Exceptions\CrossTenantWrite;
use App\Enums\OccurrenceState;
use App\Enums\ResponseChannel;
use App\Enums\ResponseType;
use App\Enums\TaskStatus;
use App\Models\CompletionResponse;
use App\Models\Task;
use App\Models\TaskOccurrence;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * #7: schema and model invariants for tasks, occurrences and responses.
 */
class TaskPersistenceTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_task_exists_independently_of_occurrences(): void
    {
        $task = $this->asTenant($this->alice(), fn () => Task::factory()->create());

        $this->assertSame(TaskStatus::Open, $task->status);
        $this->assertSame(0, $this->asTenant($this->alice(), fn () => $task->occurrences()->count()));
        $this->assertSame($this->accountOf($this->alice())->id, $task->account_id);
    }

    public function test_task_can_have_many_occurrences_without_duplicating_the_task(): void
    {
        $alice = $this->alice();

        $this->asTenant($alice, function () {
            $task = Task::factory()->create();
            TaskOccurrence::factory()->forTask($task)->at('2026-11-09 15:00:00')->missed()->create();
            TaskOccurrence::factory()->forTask($task)->at('2026-11-10 15:00:00')->create();

            $this->assertSame(1, Task::count());
            $this->assertSame(2, $task->occurrences()->count());
            $this->assertSame(OccurrenceState::Missed, $task->occurrences()->first()?->state);
        });
    }

    public function test_occurrence_cannot_be_attached_to_another_tenants_task(): void
    {
        $bobTask = $this->asTenant($this->bob(), fn () => Task::factory()->create());

        $this->expectException(CrossTenantWrite::class);

        $this->asTenant($this->alice(), function () use ($bobTask) {
            $occurrence = new TaskOccurrence([
                'scheduled_start_at' => now(),
                'scheduled_end_at' => now()->addMinutes(15),
                'timezone' => 'UTC',
            ]);
            $occurrence->task_id = $bobTask->id;
            $occurrence->save();
        });
    }

    public function test_database_rejects_an_occurrence_that_ends_before_it_starts(): void
    {
        $task = $this->asTenant($this->alice(), fn () => Task::factory()->create());

        $this->expectException(QueryException::class);

        DB::table('task_occurrences')->insert([
            'id' => (string) str()->ulid(),
            'account_id' => $task->account_id,
            'task_id' => $task->id,
            'scheduled_start_at' => '2026-11-09 15:15:00',
            'scheduled_end_at' => '2026-11-09 15:00:00',
            'timezone' => 'UTC',
            'state' => 'scheduled',
            'version' => 1,
        ]);
    }

    public function test_task_fields_controlling_ownership_and_state_are_not_mass_assignable(): void
    {
        $task = new Task([
            'title' => 'x',
            'account_id' => 'forged',
            'owner_user_id' => 999,
            'status' => 'completed',
        ]);

        $this->assertNull($task->account_id);
        $this->assertNull($task->owner_user_id);
        $this->assertSame(TaskStatus::Open, $task->status);
    }

    public function test_soft_deleted_task_keeps_its_history(): void
    {
        $this->asTenant($this->alice(), function () {
            $task = Task::factory()->create();
            TaskOccurrence::factory()->forTask($task)->create();
            $task->delete();

            $this->assertSame(0, Task::count());
            $this->assertSame(1, Task::withTrashed()->count());
            $this->assertSame(1, TaskOccurrence::count());
        });
    }

    public function test_idempotency_keys_are_unique_per_account_not_globally(): void
    {
        $record = function (string $key) {
            $task = Task::factory()->create();
            $response = new CompletionResponse;
            $response->forceFill([
                'task_id' => $task->id,
                'user_id' => $task->owner_user_id,
                'response' => ResponseType::Yes,
                'response_at' => now(),
                'channel' => ResponseChannel::Api,
                'idempotency_key' => $key,
            ])->save();

            return $response;
        };

        // Same key in two different accounts is fine (no cross-tenant collision)...
        $this->asTenant($this->alice(), fn () => $record('shared-key'));
        $this->asTenant($this->bob(), fn () => $record('shared-key'));
        $this->assertSame(2, $this->withoutTenancy(fn () => CompletionResponse::count()));

        // ...but repeated within one account is rejected by the database.
        $this->expectException(QueryException::class);
        $this->asTenant($this->alice(), fn () => $record('shared-key'));
    }

    public function test_completion_responses_are_append_only(): void
    {
        $this->asTenant($this->alice(), function () {
            $task = Task::factory()->create();
            $response = new CompletionResponse;
            $response->forceFill([
                'task_id' => $task->id,
                'response' => ResponseType::No,
                'response_at' => now(),
                'channel' => ResponseChannel::Web,
                'idempotency_key' => 'k1',
            ])->save();

            $this->expectException(LogicException::class);
            $response->forceFill(['response' => ResponseType::Yes])->save();
        });
    }
}
