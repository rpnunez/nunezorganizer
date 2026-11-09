<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('occurrence_id')->nullable()->constrained('task_occurrences')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('channel', 32);
            $table->string('status', 32)->default('pending');
            $table->dateTime('due_at');
            $table->dateTime('sent_at')->nullable();
            $table->string('external_message_id')->nullable();
            $table->unsignedInteger('occurrence_version')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['occurrence_id', 'kind', 'channel']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('notification_actions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('delivery_id')->constrained('notification_deliveries')->cascadeOnDelete();
            $table->string('action', 32);
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->timestamps();
            $table->unique(['delivery_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_actions');
        Schema::dropIfExists('notification_deliveries');
    }
};
