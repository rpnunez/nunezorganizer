<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routine_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('template_key', 48);
            $table->unsignedTinyInteger('current_week')->default(1);
            $table->date('started_on');
            $table->string('status', 16)->default('active');
            $table->json('settings');
            $table->timestamps();
        });

        Schema::create('routine_plan_advancements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignUlid('routine_plan_id')->constrained('routine_plans')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('from_week');
            $table->string('idempotency_key', 120)->unique();
            $table->dateTime('accepted_at');
            $table->timestamps();
            $table->unique(['routine_plan_id', 'from_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_plan_advancements');
        Schema::dropIfExists('routine_plans');
    }
};
