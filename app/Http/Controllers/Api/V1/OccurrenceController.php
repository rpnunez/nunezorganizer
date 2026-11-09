<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Scheduling\Actions\CancelOccurrence;
use App\Domain\Scheduling\Actions\RecordResponse;
use App\Domain\Scheduling\Actions\RescheduleOccurrence;
use App\Domain\Scheduling\Actions\ScheduleOccurrence;
use App\Enums\ResponseChannel;
use App\Enums\ResponseType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\OccurrenceTimesRequest;
use App\Http\Requests\Api\RecordResponseRequest;
use App\Http\Resources\CompletionResponseResource;
use App\Http\Resources\OccurrenceResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\TaskOccurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OccurrenceController extends Controller
{
    use ResolvesApiUser;

    /** POST /tasks/{task}/occurrences — schedule another attempt of the same task. */
    public function store(OccurrenceTimesRequest $request, Task $task, ScheduleOccurrence $schedule): JsonResponse
    {
        Gate::authorize('update', $task);

        $occurrence = $schedule->handle(
            $task,
            $request->string('starts_at')->toString(),
            $request->string('ends_at')->toString(),
            $request->string('timezone')->toString(),
            $this->apiUser($request),
        );

        return (new OccurrenceResource($occurrence->refresh()))->response()->setStatusCode(201);
    }

    /** PATCH /occurrences/{occurrence} — move an upcoming block. */
    public function update(OccurrenceTimesRequest $request, TaskOccurrence $occurrence, RescheduleOccurrence $reschedule): OccurrenceResource
    {
        Gate::authorize('update', $this->taskOf($occurrence));

        return new OccurrenceResource($reschedule->handle(
            $occurrence,
            $request->string('starts_at')->toString(),
            $request->string('ends_at')->toString(),
            $request->string('timezone')->toString(),
            $this->apiUser($request),
        ));
    }

    /** POST /occurrences/{occurrence}/cancel — cancel one block; the task stays open. */
    public function cancel(TaskOccurrence $occurrence, CancelOccurrence $cancel): OccurrenceResource
    {
        Gate::authorize('update', $this->taskOf($occurrence));

        return new OccurrenceResource($cancel->handle($occurrence, $this->apiUser(request())));
    }

    /** POST /occurrences/{occurrence}/responses — yes | no | partial. */
    public function respond(RecordResponseRequest $request, TaskOccurrence $occurrence, RecordResponse $record): JsonResponse
    {
        $task = $this->taskOf($occurrence);
        Gate::authorize('update', $task);

        $user = $this->apiUser($request);
        $completedAt = $request->filled('completed_at')
            ? ScheduleOccurrence::toUtc($request->string('completed_at')->toString(), $this->userTimezone($user))
            : null;

        $outcome = $record->handle(
            $task,
            $occurrence,
            ResponseType::from($request->string('response')->toString()),
            $user,
            ResponseChannel::Api,
            $this->responseIdempotencyKey($request),
            $completedAt,
            $request->filled('notes') ? $request->string('notes')->toString() : null,
        );

        return response()->json([
            'response' => new CompletionResponseResource($outcome->response),
            'task' => new TaskResource($outcome->task->refresh()),
            'occurrence' => $outcome->occurrence ? new OccurrenceResource($outcome->occurrence->refresh()) : null,
            'replayed' => $outcome->replayed,
        ], $outcome->replayed ? 200 : 201);
    }

    private function taskOf(TaskOccurrence $occurrence): Task
    {
        // Tenant-scoped lookup; the occurrence itself was already resolved within the tenant.
        return Task::query()->findOrFail($occurrence->task_id);
    }
}
