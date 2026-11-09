<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NotificationHealth extends Command
{
    protected $signature = 'nunez:health';

    protected $description = 'Report notification queue age and failed job count';

    public function handle(): int
    {
        $oldest = DB::table('notification_deliveries')->where('status', 'pending')->min('due_at');
        $failed = DB::table('notification_deliveries')->where('status', 'failed')->count();
        $failedJobs = DB::table('failed_jobs')->count();
        $this->line('Oldest pending delivery: '.($oldest ?? 'none'));
        $this->line("Failed deliveries: {$failed}; failed jobs: {$failedJobs}");

        return self::SUCCESS;
    }
}
