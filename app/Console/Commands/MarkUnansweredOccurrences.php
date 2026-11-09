<?php

namespace App\Console\Commands;

use App\Domain\Audit\Audit;
use App\Enums\OccurrenceState;
use App\Enums\TaskStatus;
use App\Models\TaskOccurrence;
use App\Models\UserPreference;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ages ended, unanswered blocks into `unanswered` once the OWNER's configured
 * timeout (user_preferences.unanswered_after_minutes, default 12h) has passed
 * since the planned end. Unanswered is not "no": the task stays open and keeps
 * appearing in the backlog. Safe to re-run; scheduled every five minutes.
 */
class MarkUnansweredOccurrences extends Command
{
    protected $signature = 'nunez:mark-unanswered {--chunk=500 : Rows per batch}';

    protected $description = 'Mark ended blocks with no answer as unanswered, using each user\'s timeout';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $total = 0;

        do {
            $ids = $this->candidateIds($chunk);

            foreach ($ids as $id) {
                $total += $this->markOne($id);
            }
        } while (count($ids) === $chunk);

        $this->info("Marked {$total} block(s) as unanswered.");

        return self::SUCCESS;
    }

    /**
     * System-level scan across all tenants (raw query builder; no tenant scope applies).
     *
     * @return list<string> occurrence IDs
     */
    private function candidateIds(int $limit): array
    {
        $now = now()->utc()->format('Y-m-d H:i:s');
        $default = UserPreference::DEFAULT_UNANSWERED_AFTER_MINUTES;

        return array_values(DB::table('task_occurrences as o')
            ->join('tasks as t', 't.id', '=', 'o.task_id')
            ->leftJoin('user_preferences as p', 'p.user_id', '=', 't.owner_user_id')
            ->whereIn('o.state', [OccurrenceState::Scheduled->value, OccurrenceState::AwaitingResponse->value])
            ->where('t.status', TaskStatus::Open->value)
            ->whereNull('t.deleted_at')
            ->whereRaw('o.scheduled_end_at <= DATE_SUB(?, INTERVAL COALESCE(p.unanswered_after_minutes, ?) MINUTE)', [$now, $default])
            ->orderBy('o.scheduled_end_at')
            ->limit($limit)
            ->pluck('o.id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    private function markOne(string $occurrenceId): int
    {
        return DB::transaction(function () use ($occurrenceId): int {
            /** @var TaskOccurrence|null $occurrence */
            $occurrence = TaskOccurrence::withoutGlobalScopes()->whereKey($occurrenceId)->lockForUpdate()->first();

            // Re-check under lock: a response may have arrived since the scan.
            if ($occurrence === null || ! in_array($occurrence->state, [OccurrenceState::Scheduled, OccurrenceState::AwaitingResponse], true)) {
                return 0;
            }

            $occurrence->forceFill(['state' => OccurrenceState::Unanswered, 'closed_at' => now()])->save();

            Audit::record('occurrence.unanswered', $occurrence, [
                'task_id' => $occurrence->task_id,
                'planned_end' => $occurrence->scheduled_end_at->toIso8601ZuluString(),
            ], accountId: $occurrence->account_id);

            return 1;
        });
    }
}
