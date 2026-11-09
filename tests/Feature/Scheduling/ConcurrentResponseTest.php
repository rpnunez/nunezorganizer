<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Accounts\TenantContext;
use App\Domain\Scheduling\Actions\RecordResponse;
use App\Domain\Tasks\Exceptions\TaskStateConflict;
use App\Enums\ResponseChannel;
use App\Enums\ResponseType;
use App\Enums\TaskStatus;
use App\Models\AuditEvent;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Real two-connection race on MariaDB (ADR 0005): while another connection holds the
 * task row (an in-flight Yes from the web), a Telegram Yes must WAIT for the lock, and
 * once the first commits, the second must not complete the task again.
 *
 * Uses committed data (no wrapping transaction), so it cleans every table afterwards.
 */
class ConcurrentResponseTest extends TestCase
{
    private const string OTHER = 'mariadb_concurrency';

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('tasks')) {
            Artisan::call('migrate:fresh');
        }

        $this->wipe();
        config(['database.connections.'.self::OTHER => config('database.connections.mariadb')]);
    }

    protected function tearDown(): void
    {
        DB::connection(self::OTHER)->rollBack();
        DB::purge(self::OTHER);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        $this->wipe();

        parent::tearDown();
    }

    public function test_competing_yes_waits_for_the_lock_and_does_not_complete_twice(): void
    {
        $alice = User::factory()->create(['email' => 'alice@example.test']);
        $tenant = app(TenantContext::class);

        [$task, $occurrence] = $tenant->runAs($alice, function () {
            $task = Task::factory()->create();

            return [$task, TaskOccurrence::factory()->forTask($task)->awaitingResponse()->create()];
        });

        // Connection B: another request has locked the task and is mid-way through completing it.
        $other = DB::connection(self::OTHER);
        $other->beginTransaction();
        $other->select('SELECT id FROM tasks WHERE id = ? FOR UPDATE', [$task->id]);

        // Connection A: the Telegram callback must block on the lock, not race past it.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = false;

        try {
            $tenant->runAs($alice, fn () => app(RecordResponse::class)->handle(
                $task, $occurrence, ResponseType::Yes, $alice, ResponseChannel::Telegram, 'tg:race-2',
            ));
        } catch (QueryException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
        }

        $this->assertTrue($blocked, 'The second response must wait for the task row lock.');
        $this->assertSame(TaskStatus::Open, Task::withoutGlobalScopes()->findOrFail($task->id)->status, 'Nothing applied while blocked.');

        // B finishes first: the task is now completed and B releases the lock.
        $other->update('UPDATE tasks SET status = ?, completed_at = NOW() WHERE id = ?', ['completed', $task->id]);
        $other->commit();

        // A retries (e.g. Telegram redelivers): it sees the committed state and refuses to complete twice.
        $this->expectException(TaskStateConflict::class);

        try {
            $tenant->runAs($alice, fn () => app(RecordResponse::class)->handle(
                $task, $occurrence, ResponseType::Yes, $alice, ResponseChannel::Telegram, 'tg:race-2',
            ));
        } finally {
            $this->assertSame(0, AuditEvent::where('action', 'task.completed')->count());
        }
    }

    private function wipe(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];

            if ($table !== 'migrations') {
                DB::table($table)->truncate();
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
