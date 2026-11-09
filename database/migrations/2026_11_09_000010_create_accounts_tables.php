<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('type', 32)->default('personal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('account_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['account_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedSmallInteger('grace_minutes')->default(15);
            $table->unsignedSmallInteger('unanswered_after_minutes')->default(720);
            $table->json('work_windows')->nullable();
            $table->json('quiet_hours')->nullable();
            $table->json('review_times')->nullable();
            $table->json('notification_preferences')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('account_memberships');
        Schema::dropIfExists('accounts');
    }
};
