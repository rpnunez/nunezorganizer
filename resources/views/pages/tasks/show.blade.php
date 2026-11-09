<?php

use App\Livewire\SchedulingPage;
use App\Domain\Scheduling\Actions\CorrectCompletionTime;
use App\Domain\Scheduling\Actions\RescheduleOccurrence;
use App\Domain\Scheduling\Actions\ScheduleOccurrence;
use App\Domain\Scheduling\LocalTime;
use App\Domain\Tasks\Actions\CancelTask;
use App\Enums\OccurrenceState;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Support\LocalClock;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

new #[Title('Task')] class extends SchedulingPage {
    /** Only the ID is kept in client state; the task is re-loaded (tenant-scoped) on every request. */
    #[Locked]
    public string $taskId = '';

    public ?string $moving = null;
    public string $moveDate = '';
    public string $moveStart = '';
    public string $moveEnd = '';

    public string $doneDate = '';
    public string $doneTime = '';

    public function mount(Task $task): void
    {
        Gate::authorize('view', $task);
        $this->taskId = $task->id;
        $this->defaultBlock($this->timezone, $task->duration_minutes);
    }

    #[Computed]
    public function task(): Task
    {
        $task = Task::query()->with(['occurrences', 'responses'])->findOrFail($this->taskId);
        Gate::authorize('view', $task);

        return $task;
    }

    #[Computed]
    public function timezone(): string
    {
        return $this->userTimezone();
    }

    public function scheduleAttempt(ScheduleOccurrence $schedule): void
    {
        Gate::authorize('update', $this->task);
        $this->validate($this->blockRules());

        $times = $this->blockTimes($this->date, $this->start, $this->end);
        if ($this->attempt(fn () => $schedule->handle($this->task, $times['starts_at'], $times['ends_at'], $this->timezone, $this->currentUser()))) {
            Flux::toast(variant: 'success', text: __('Scheduled another attempt.'));
            unset($this->task);
        }
    }

    public function startMove(string $occurrenceId): void
    {
        $block = $this->task->occurrences->firstWhere('id', $occurrenceId);
        abort_if($block === null, 404);

        $start = $block->scheduled_start_at->setTimezone($this->timezone);
        $this->moving = $block->id;
        $this->moveDate = $start->format('Y-m-d');
        $this->moveStart = $start->format('H:i');
        $this->moveEnd = $block->scheduled_end_at->setTimezone($this->timezone)->format('H:i');
    }

    public function saveMove(RescheduleOccurrence $reschedule): void
    {
        Gate::authorize('update', $this->task);
        $this->validate([
            'moveDate' => ['required', 'date_format:Y-m-d'],
            'moveStart' => ['required', 'date_format:H:i'],
            'moveEnd' => ['required', 'date_format:H:i', 'after:moveStart'],
        ]);

        $block = TaskOccurrence::query()->where('task_id', $this->taskId)->findOrFail((string) $this->moving);
        $times = $this->blockTimes($this->moveDate, $this->moveStart, $this->moveEnd);

        if ($this->attempt(fn () => $reschedule->handle($block, $times['starts_at'], $times['ends_at'], $this->timezone, $this->currentUser()))) {
            Flux::toast(variant: 'success', text: __('Block moved.'));
            $this->moving = null;
            unset($this->task);
        }
    }

    public function cancelTask(CancelTask $cancel): void
    {
        Gate::authorize('cancel', $this->task);

        if ($this->attempt(fn () => $cancel->handle($this->task, $this->currentUser()))) {
            Flux::modal('confirm-cancel')->close();
            Flux::toast(text: __('Task cancelled.'));
            unset($this->task);
        }
    }

    public function correctCompletion(CorrectCompletionTime $correct): void
    {
        Gate::authorize('update', $this->task);
        $this->validate(['doneDate' => ['required', 'date_format:Y-m-d'], 'doneTime' => ['required', 'date_format:H:i']]);

        $at = LocalTime::toUtc("{$this->doneDate} {$this->doneTime}", $this->timezone);
        if ($this->attempt(fn () => $correct->handle($this->task, $at, $this->currentUser()))) {
            Flux::toast(variant: 'success', text: __('Completion time updated.'));
            unset($this->task);
        }
    }

    public function render()
    {
        return $this->view()->title($this->task->title);
    }
}; ?>

@php
    $task = $this->task;
    $tz = $this->timezone;
    $open = $task->status === TaskStatus::Open;
@endphp

<div class="mx-auto flex w-full max-w-2xl flex-col gap-8">
    <flux:link :href="route('tasks.index')" wire:navigate class="flex items-center gap-1 text-sm">
        <flux:icon.chevron-left class="size-4" /> {{ __('Tasks') }}
    </flux:link>

    {{-- Header --}}
    <header class="flex flex-col gap-3">
        <div class="flex items-start justify-between gap-3">
            <flux:heading size="xl" level="1" class="break-words">{{ $task->title }}</flux:heading>
            <x-state-badge :state="$task->status" />
        </div>
        <flux:text>
            {{ trans_choice(':count minute|:count minutes', $task->duration_minutes, ['count' => $task->duration_minutes]) }}
            @if ($task->completed_at)
                · {{ __('Done :when', ['when' => LocalClock::dayAndTime($task->completed_at, $tz)]) }}
            @endif
        </flux:text>
        @if ($task->description)
            <flux:text class="whitespace-pre-line">{{ $task->description }}</flux:text>
        @endif

        @if ($open)
            <div class="flex flex-wrap gap-2">
                <flux:button variant="primary" icon="check" class="min-h-11" wire:click="completeTask('{{ $task->id }}')" wire:loading.attr="disabled" wire:target="completeTask">
                    {{ __('Mark done') }}
                </flux:button>
                <flux:button icon="pencil-square" class="min-h-11" :href="route('tasks.edit', $task)" wire:navigate>{{ __('Edit') }}</flux:button>
                <flux:modal.trigger name="confirm-cancel">
                    <flux:button variant="ghost" icon="x-circle" class="min-h-11">{{ __('Cancel task') }}</flux:button>
                </flux:modal.trigger>
            </div>
        @endif
    </header>

    {{-- Schedule another attempt of the SAME task --}}
    @if ($open)
        <section id="schedule" aria-labelledby="schedule-heading" class="flex flex-col gap-3">
            <flux:heading level="2" id="schedule-heading">{{ __('Schedule a block') }}</flux:heading>
            <form wire:submit="scheduleAttempt" class="flex flex-col gap-3">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <flux:input wire:model="date" type="date" :label="__('Date')" required />
                    <flux:input wire:model="start" type="time" :label="__('Start')" required />
                    <flux:input wire:model="end" type="time" :label="__('End')" required />
                </div>
                <div>
                    <flux:button type="submit" icon="calendar" class="min-h-11" wire:loading.attr="disabled" wire:target="scheduleAttempt">{{ __('Schedule') }}</flux:button>
                </div>
                <flux:text size="sm">{{ __('Times are in :tz.', ['tz' => $tz]) }}</flux:text>
            </form>
        </section>
    @endif

    {{-- Attempts (history) --}}
    <section aria-labelledby="attempts-heading" class="flex flex-col gap-3">
        <flux:heading level="2" id="attempts-heading">{{ __('Attempts') }}</flux:heading>

        @if ($task->occurrences->isEmpty())
            <flux:text>{{ __('Not scheduled yet.') }}</flux:text>
        @else
            <ul class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" role="list">
                @foreach ($task->occurrences->sortByDesc('scheduled_start_at') as $block)
                    @php
                        $started = $block->scheduled_start_at->lessThanOrEqualTo(now());
                        $answerable = $open && $started && ! $block->state->isTerminal() && $block->state !== OccurrenceState::Missed;
                        $movable = $open && $block->state === OccurrenceState::Scheduled && ! $started;
                    @endphp
                    <li wire:key="attempt-{{ $block->id }}" class="flex flex-col gap-3 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <flux:text class="font-medium">{{ LocalClock::range($block->scheduled_start_at, $block->scheduled_end_at, $tz) }}</flux:text>
                            <x-state-badge :state="$block->state" :ends-at="$block->scheduled_end_at" />
                        </div>

                        @if ($answerable)
                            <div class="grid grid-cols-2 gap-2">
                                <flux:button variant="primary" icon="check" class="min-h-11" wire:click="respond('{{ $block->id }}', 'yes')" wire:loading.attr="disabled" wire:target="respond">{{ __('Yes, done') }}</flux:button>
                                <flux:button icon="x-mark" class="min-h-11" wire:click="respond('{{ $block->id }}', 'no')" wire:loading.attr="disabled" wire:target="respond">{{ __('Not done') }}</flux:button>
                            </div>
                        @endif

                        @if ($movable)
                            @if ($moving === $block->id)
                                <form wire:submit="saveMove" class="flex flex-col gap-3">
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                        <flux:input wire:model="moveDate" type="date" :label="__('Date')" required />
                                        <flux:input wire:model="moveStart" type="time" :label="__('Start')" required />
                                        <flux:input wire:model="moveEnd" type="time" :label="__('End')" required />
                                    </div>
                                    <div class="flex gap-2">
                                        <flux:button type="submit" size="sm" variant="primary" class="min-h-11">{{ __('Save') }}</flux:button>
                                        <flux:button size="sm" variant="ghost" class="min-h-11" wire:click="$set('moving', null)">{{ __('Back') }}</flux:button>
                                    </div>
                                </form>
                            @else
                                <div class="flex gap-2">
                                    <flux:button size="sm" icon="arrows-right-left" class="min-h-11" wire:click="startMove('{{ $block->id }}')">{{ __('Move') }}</flux:button>
                                    <flux:button size="sm" variant="ghost" class="min-h-11" wire:click="cancelOccurrence('{{ $block->id }}')" wire:confirm="{{ __('Cancel this block? The task stays open.') }}">{{ __('Cancel block') }}</flux:button>
                                </div>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Answers (append-only log) --}}
    @if ($task->responses->isNotEmpty())
        <section aria-labelledby="answers-heading" class="flex flex-col gap-3">
            <flux:heading level="2" id="answers-heading">{{ __('Answers') }}</flux:heading>
            <ul class="flex flex-col gap-1" role="list">
                @foreach ($task->responses->sortByDesc('response_at') as $answer)
                    <li wire:key="answer-{{ $answer->id }}">
                        <flux:text size="sm">
                            <span class="font-medium">{{ match ($answer->response->value) { 'yes' => __('Yes'), 'no' => __('No'), default => ucfirst($answer->response->value) } }}</span>
                            · {{ LocalClock::dayAndTime($answer->response_at, $tz) }}
                            · {{ ucfirst($answer->channel->value) }}
                            @if ($answer->punctuality)
                                · {{ str_replace('_', ' ', $answer->punctuality->value) }}
                            @endif
                            @if ($answer->notes)
                                · {{ $answer->notes }}
                            @endif
                        </flux:text>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Correct completion time --}}
    @if ($task->status === TaskStatus::Completed)
        <section aria-labelledby="correct-heading" class="flex flex-col gap-3">
            <flux:heading level="2" id="correct-heading">{{ __('Finished at a different time?') }}</flux:heading>
            <form wire:submit="correctCompletion" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <flux:input wire:model="doneDate" type="date" :label="__('Date')" required />
                <flux:input wire:model="doneTime" type="time" :label="__('Time')" required />
                <flux:button type="submit" class="min-h-11">{{ __('Update') }}</flux:button>
            </form>
        </section>
    @endif

    <flux:modal name="confirm-cancel" class="max-w-sm">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Cancel this task?') }}</flux:heading>
            <flux:text>{{ __('It will leave your backlog and upcoming blocks will be cancelled. Its history is kept.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Keep it') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="cancelTask" wire:loading.attr="disabled">{{ __('Cancel task') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
