<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('local_date');
            $table->string('kind', 16);
            $table->string('status', 16)->default('pending');
            $table->dateTime('sent_at')->nullable();
            $table->ulid('delivery_id')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'local_date', 'kind']);
        });

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->foreignUlid('daily_review_id')->nullable()->after('occurrence_id')
                ->constrained('daily_reviews')->cascadeOnDelete();
            $table->unique(['daily_review_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropForeign(['daily_review_id']);
            $table->dropUnique(['daily_review_id', 'channel']);
            $table->dropColumn('daily_review_id');
        });
        Schema::dropIfExists('daily_reviews');
    }
};
