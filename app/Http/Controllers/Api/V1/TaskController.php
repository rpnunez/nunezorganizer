<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tasks\Actions\CancelTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTaskRequest;
use App\Http\Requests\Api\UpdateTaskRequest;
use App\Http\Resources\OccurrenceResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Task::class);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $tasks = Task::query()
            ->where('owner_user_id', $this->user($request)->id)
            ->when(isset($validated['status']), fn ($q) => $q->where('status', $validated['status']))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString();

        return TaskResource::collection($tasks);
    }

    public function store(StoreTaskRequest $request, CreateTask $createTask): JsonResponse
    {
        Gate::authorize('create', Task::class);

        /** @var array{title: string, description?: string|null, duration_minutes?: int, occurrence?: array{starts_at: string, ends_at: string, timezone: string}} $data */
        $data = $request->validated();

        ['task' => $task, 'occurrence' => $occurrence] = $createTask->handle(
            $this->user($request),
            $data,
            $data['occurrence'] ?? null,
        );

        return response()->json([
            'task' => new TaskResource($task->refresh()),
            'occurrence' => $occurrence ? new OccurrenceResource($occurrence->refresh()) : null,
        ], 201);
    }

    public function show(Task $task): TaskResource
    {
        Gate::authorize('view', $task);

        return new TaskResource($task->load('occurrences'));
    }

    public function update(UpdateTaskRequest $request, Task $task, UpdateTask $updateTask): TaskResource
    {
        Gate::authorize('update', $task);

        /** @var array{title?: string, description?: string|null, duration_minutes?: int} $data */
        $data = $request->validated();

        return new TaskResource($updateTask->handle($task, $data, $this->user($request)));
    }

    public function cancel(Request $request, Task $task, CancelTask $cancelTask): TaskResource
    {
        Gate::authorize('cancel', $task);

        return new TaskResource($cancelTask->handle($task, $this->user($request)));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
