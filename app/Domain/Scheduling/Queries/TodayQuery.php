<?php

namespace App\Domain\Scheduling\Queries;

use App\Enums\OccurrenceState;
use App\Enums\TaskStatus;
use App\Models\TaskOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The user's plan for one local day: blocks starting that day (any state), plus
 * blocks that ended and are still waiting for an answer.
 */
class TodayQuery
{
    /**
     * @return Collection<int, TaskOccurrence>
     */
    public function blocks(User $user, LocalDay $day): Collection
    {
        return TaskOccurrence::query()
            ->whereHas('task', fn ($q) => $q->where('owner_user_id', $user->id))
            ->where('scheduled_start_at', '>=', $day->startUtc)
            ->where('scheduled_start_at', '<', $day->endUtc)
            ->with('task')
            ->orderBy('scheduled_start_at')
            ->get();
    }

    /**
     * Ended blocks of open tasks awaiting a yes/no (at most 50, most recent first).
     *
     * @return Collection<int, TaskOccurrence>
     */
    public function pendingResponses(User $user): Collection
    {
        return TaskOccurrence::query()
            ->whereHas('task', fn ($q) => $q->where('owner_user_id', $user->id)->where('status', TaskStatus::Open))
            ->where('state', OccurrenceState::AwaitingResponse)
            ->with('task')
            ->latest('scheduled_end_at')
            ->limit(50)
            ->get();
    }
}
