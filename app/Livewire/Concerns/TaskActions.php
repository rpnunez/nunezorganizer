<?php

namespace App\Livewire\Concerns;

use App\Domain\Scheduling\Actions\CancelOccurrence;
use App\Domain\Scheduling\Actions\RecordResponse;
use App\Domain\Tasks\Exceptions\TaskStateConflict;
use App\Enums\OccurrenceState;
use App\Enums\ResponseChannel;
use App\Enums\ResponseType;
use App\Models\Task;
use App\Models\TaskOccurrence;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Quick actions shared by Today, Backlog and the task page. Requires InTenant (see TaskActionsPage). Every action goes through
 * the same domain services as the API and Telegram.
 *
 * Double-submit safety: the page gets one nonce when it loads; the idempotency key is
 * (nonce, target, action), so a double tap or a retried request on the same button
 * replays instead of applying twice, while different targets/actions stay distinct.
 */
trait TaskActions
{
    public string $nonce = '';

    public function mountTaskActions(): void
    {
        $this->nonce = (string) Str::ulid();
    }

    public function respond(string $occurrenceId, string $response): void
    {
        $type = ResponseType::tryFrom($response);
        abort_unless(in_array($type, [ResponseType::Yes, ResponseType::No], true), 422);

        // Tenant-scoped lookups: another user's IDs are simply not found (404).
        $occurrence = TaskOccurrence::query()->findOrFail($occurrenceId);
        $task = Task::query()->findOrFail($occurrence->task_id);
        Gate::authorize('update', $task);

        $this->attempt(function () use ($task, $occurrence, $type) {
            $outcome = app(RecordResponse::class)->handle(
                $task, $occurrence, $type, $this->currentUser(), ResponseChannel::Web,
                "web:{$this->nonce}:{$occurrence->id}:{$type->value}",
            );

            if (! $outcome->replayed) {
                Flux::toast(
                    variant: $type === ResponseType::Yes ? 'success' : 'warning',
                    text: $type === ResponseType::Yes
                        ? __('Nice work: ":title" is done.', ['title' => $task->title])
                        : __('Noted. ":title" stays in your backlog.', ['title' => $task->title]),
                );
            }
        });
    }

    public function completeTask(string $taskId): void
    {
        $task = Task::query()->findOrFail($taskId);
        Gate::authorize('update', $task);

        $current = TaskOccurrence::query()
            ->where('task_id', $task->id)
            ->whereNotIn('state', [OccurrenceState::Finished, OccurrenceState::Cancelled])
            ->where('scheduled_start_at', '<=', now())
            ->latest('scheduled_start_at')
            ->first();

        $this->attempt(function () use ($task, $current) {
            $outcome = app(RecordResponse::class)->handle(
                $task, $current, ResponseType::Yes, $this->currentUser(), ResponseChannel::Web,
                "web:{$this->nonce}:{$task->id}:complete",
            );

            if (! $outcome->replayed) {
                Flux::toast(variant: 'success', text: __('Nice work: ":title" is done.', ['title' => $task->title]));
            }
        });
    }

    public function cancelOccurrence(string $occurrenceId): void
    {
        $occurrence = TaskOccurrence::query()->findOrFail($occurrenceId);
        Gate::authorize('update', Task::query()->findOrFail($occurrence->task_id));

        $this->attempt(function () use ($occurrence) {
            app(CancelOccurrence::class)->handle($occurrence, $this->currentUser());
            Flux::toast(text: __('Block cancelled. The task is still open.'));
        });
    }

    /**
     * Run an action, turning domain conflicts into friendly messages instead of errors.
     */
    protected function attempt(callable $action): bool
    {
        try {
            $action();
        } catch (TaskStateConflict $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return false;
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: (string) collect($e->errors())->flatten()->first());

            return false;
        }

        return true;
    }
}
