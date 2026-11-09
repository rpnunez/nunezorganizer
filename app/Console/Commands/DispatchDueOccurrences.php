<?php

namespace App\Console\Commands;

use App\Domain\Accounts\TenantContext;
use App\Domain\Notifications\Jobs\SendCompletionPrompt;
use App\Enums\OccurrenceState;
use App\Enums\TaskStatus;
use App\Models\NotificationDelivery;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Claims due attempts exactly once using the task/occurrence lock order. */
class DispatchDueOccurrences extends Command
{
    protected $signature = 'nunez:dispatch-due {--chunk=500 : Rows per scan batch}';

    protected $description = 'Queue end-of-block completion prompts for due occurrences';

    public function handle(TenantContext $tenant): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $after = '';
        $queued = 0;

        do {
            $rows = DB::table('task_occurrences as o')
                ->join('tasks as t', 't.id', '=', 'o.task_id')
                ->where('o.state', OccurrenceState::Scheduled->value)
                ->where('t.status', TaskStatus::Open->value)
                ->whereNull('t.deleted_at')
                ->where('o.scheduled_end_at', '<=', now())
                ->where('o.id', '>', $after)
                ->orderBy('o.id')
                ->limit($chunk)
                ->get(['o.id', 'o.account_id', 't.owner_user_id']);

            foreach ($rows as $row) {
                $after = (string) $row->id;
                $user = User::find((int) $row->owner_user_id);
                if ($user === null) {
                    continue;
                }

                try {
                    $queued += $tenant->runAs($user, fn (): int => $this->claim((string) $row->id, $user), (string) $row->account_id);
                } catch (AuthorizationException) {
                    // An inactive membership cannot receive a notification.
                }
            }
        } while ($rows->count() === $chunk);

        $this->info("Queued {$queued} completion prompt(s).");

        return self::SUCCESS;
    }

    private function claim(string $occurrenceId, User $user): int
    {
        $deliveryId = DB::transaction(function () use ($occurrenceId, $user): ?string {
            $taskId = TaskOccurrence::query()->whereKey($occurrenceId)->value('task_id');
            if ($taskId === null) {
                return null;
            }

            $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();
            $occurrence = TaskOccurrence::query()->whereKey($occurrenceId)->lockForUpdate()->first();
            if ($task === null || $occurrence === null || $task->owner_user_id !== $user->id
                || $task->status !== TaskStatus::Open || $occurrence->state !== OccurrenceState::Scheduled
                || $occurrence->scheduled_end_at->isFuture()) {
                return null;
            }

            $delivery = NotificationDelivery::query()->firstOrCreate(
                ['occurrence_id' => $occurrence->id, 'kind' => 'end_prompt', 'channel' => 'telegram'],
                ['user_id' => $user->id, 'status' => 'pending', 'due_at' => $occurrence->scheduled_end_at,
                    'occurrence_version' => $occurrence->version],
            );

            $occurrence->forceFill(['state' => OccurrenceState::AwaitingResponse])->save();

            if (! $delivery->wasRecentlyCreated) {
                return null;
            }

            return $delivery->id;
        });

        if ($deliveryId === null) {
            return 0;
        }

        SendCompletionPrompt::dispatch($deliveryId, $user->id, app(TenantContext::class)->accountId())->onQueue('notifications');

        return 1;
    }
}
