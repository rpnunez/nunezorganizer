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
        $uncertain = DB::table('notification_deliveries')->where('status', 'uncertain')->count();
        $stuckSending = DB::table('notification_deliveries')->where('status', 'sending')
            ->where('updated_at', '<=', now()->subMinutes(10))->count();
        $failedJobs = DB::table('failed_jobs')->count();
        $this->line('Oldest pending delivery: '.($oldest ?? 'none'));
        $this->line("Failed deliveries: {$failed}; failed jobs: {$failedJobs}");
        $this->line("Uncertain deliveries: {$uncertain}");
        $this->line("Stuck sending deliveries: {$stuckSending}");
        if ($uncertain > 0) {
            foreach (DB::table('notification_deliveries')->where('status', 'uncertain')
                ->orderBy('id')->limit(20)->pluck('id') as $id) {
                $this->line('Uncertain delivery ID: '.$id);
            }
        }

        return self::SUCCESS;
    }
}
