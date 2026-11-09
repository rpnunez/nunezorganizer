<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks (what must be done) and occurrences (scheduled attempts) are separate
 * rows. Carryover never duplicates a task; it schedules another occurrence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(15);
            $table->string('status', 20)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'owner_user_id', 'status']);
        });

        Schema::create('task_occurrences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained('tasks')->cascadeOnDelete();
            // UTC instants. The planning timezone is kept for local display and review.
            $table->dateTime('scheduled_start_at');
            $table->dateTime('scheduled_end_at');
            $table->string('timezone', 64);
            $table->string('state', 32)->default('scheduled');
            $table->timestamp('closed_at')->nullable();
            // Bumped on every reschedule so queued notifications can detect stale state.
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['account_id', 'state', 'scheduled_end_at']);
            $table->index(['task_id', 'scheduled_start_at']);
        });

        Schema::create('completion_responses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignUlid('occurrence_id')->nullable()->constrained('task_occurrences')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('response', 20);
            $table->timestamp('response_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('punctuality', 20)->nullable();
            $table->string('channel', 20);
            $table->string('idempotency_key', 100);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Scoped per account so one tenant's key can never collide with (or reveal) another's.
            $table->unique(['account_id', 'idempotency_key']);
            $table->index(['task_id', 'response_at']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE task_occurrences ADD CONSTRAINT task_occurrences_end_after_start CHECK (scheduled_end_at > scheduled_start_at)');
            DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_duration_positive CHECK (duration_minutes > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('completion_responses');
        Schema::dropIfExists('task_occurrences');
        Schema::dropIfExists('tasks');
    }
};
