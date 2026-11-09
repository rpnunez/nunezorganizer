<?php

namespace App\Domain\Scheduling\Queries;

use App\Enums\OccurrenceState;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Carryover is a QUERY, not a copy: open tasks with at least one attempt that ended
 * before the start of the user's local day and was never resolved. Each logical task
 * appears exactly once, with its full attempt history, until it is completed or
 * cancelled. Nothing here ever inserts a task.
 */
class BacklogQuery
{
    /**
     * Attempt states that leave work unresolved. `scheduled` is included for blocks whose
     * end passed without any prompt being sent (e.g. notifications not configured).
     *
     * @return list<OccurrenceState>
     */
    public static function unresolvedStates(): array
    {
        return [
            OccurrenceState::Scheduled,
            OccurrenceState::AwaitingResponse,
            OccurrenceState::Missed,
            OccurrenceState::Unanswered,
        ];
    }

    /**
     * Tasks are tenant-scoped by BelongsToAccount; owner_user_id restricts to the user's own.
     *
     * @return Builder<Task>
     */
    public function query(User $user, LocalDay $day): Builder
    {
        $unresolvedBefore = fn ($q) => $q->reorder()
            ->whereIn('state', self::unresolvedStates())
            ->where('scheduled_end_at', '<', $day->startUtc);

        return Task::query()
            ->where('owner_user_id', $user->id)
            ->where('status', TaskStatus::Open)
            ->whereHas('occurrences', $unresolvedBefore)
            ->withMin(['occurrences as overdue_since' => $unresolvedBefore], 'scheduled_end_at')
            ->withCount(['occurrences as attempt_count' => fn ($q) => $q->reorder()->where('scheduled_end_at', '<', $day->startUtc)])
            ->withMin(['occurrences as next_attempt_at' => fn ($q) => $q->reorder()
                ->where('state', OccurrenceState::Scheduled)
                ->where('scheduled_end_at', '>=', $day->startUtc)], 'scheduled_start_at')
            ->with(['occurrences' => fn (HasMany $q) => $q->reorder()->orderBy('scheduled_start_at')])
            ->orderBy('overdue_since')
            ->orderBy('id');
    }

    /**
     * @return LengthAwarePaginator<int, Task>
     */
    public function paginate(User $user, LocalDay $day, int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($user, $day)->paginate($perPage);
    }

    public function count(User $user, LocalDay $day): int
    {
        return Task::query()
            ->where('owner_user_id', $user->id)
            ->where('status', TaskStatus::Open)
            ->whereHas('occurrences', fn ($q) => $q->reorder()
                ->whereIn('state', self::unresolvedStates())
                ->where('scheduled_end_at', '<', $day->startUtc))
            ->count();
    }
}
