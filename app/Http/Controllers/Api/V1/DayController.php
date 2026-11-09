<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Scheduling\Queries\BacklogQuery;
use App\Domain\Scheduling\Queries\LocalDay;
use App\Domain\Scheduling\Queries\TodayQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\BacklogItemResource;
use App\Http\Resources\OccurrenceResource;
use App\Http\Resources\TaskResource;
use App\Models\TaskOccurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Day-oriented read models, always in the authenticated user's own timezone.
 */
class DayController extends Controller
{
    use ResolvesApiUser;

    /** GET /today?date=YYYY-MM-DD */
    public function today(Request $request, TodayQuery $today, BacklogQuery $backlog): JsonResponse
    {
        $validated = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']]);
        $user = $this->apiUser($request);
        $timezone = $this->userTimezone($user);
        $day = isset($validated['date']) ? LocalDay::of($validated['date'], $timezone) : LocalDay::today($timezone);

        // resolve() (not toArray()) so unloaded relations are filtered out properly.
        $present = fn (TaskOccurrence $o) => array_merge(
            (new OccurrenceResource($o))->resolve($request),
            ['task' => $o->task ? (new TaskResource($o->task))->resolve($request) : null],
        );

        return response()->json([
            'date' => $day->date,
            'timezone' => $timezone,
            'blocks' => $today->blocks($user, $day)->map($present)->values(),
            'pending_responses' => $today->pendingResponses($user)->map($present)->values(),
            'backlog_count' => $backlog->count($user, $day),
        ]);
    }

    /** GET /backlog?date=YYYY-MM-DD&per_page=25 */
    public function backlog(Request $request, BacklogQuery $backlog): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $this->apiUser($request);
        $timezone = $this->userTimezone($user);
        $day = isset($validated['date']) ? LocalDay::of($validated['date'], $timezone) : LocalDay::today($timezone);

        return BacklogItemResource::collection(
            $backlog->paginate($user, $day, (int) ($validated['per_page'] ?? 25))->withQueryString()
        )->additional(['date' => $day->date, 'timezone' => $timezone]);
    }
}
