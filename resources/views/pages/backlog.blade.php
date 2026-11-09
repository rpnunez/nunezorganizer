<?php

use App\Livewire\TaskActionsPage;
use App\Domain\Scheduling\Queries\BacklogQuery;
use App\Domain\Scheduling\Queries\LocalDay;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

new #[Title('Backlog')] class extends TaskActionsPage {
    use WithPagination;

    #[Computed]
    public function timezone(): string
    {
        return $this->userTimezone();
    }

    #[Computed]
    public function items()
    {
        return app(BacklogQuery::class)->paginate($this->currentUser(), LocalDay::today($this->timezone), 20);
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
    <header>
        <flux:heading size="xl" level="1">{{ __('Backlog') }}</flux:heading>
        <flux:text>{{ __('Unfinished work carried over from earlier days. Each task appears once until it is done or cancelled.') }}</flux:text>
    </header>

    @if ($this->items->isEmpty())
        <flux:card class="flex flex-col items-start gap-2 p-6">
            <flux:heading level="2">{{ __('All caught up') }}</flux:heading>
            <flux:text>{{ __('Nothing is carried over. Anything you miss will show up here tomorrow.') }}</flux:text>
        </flux:card>
    @else
        <ul class="flex flex-col gap-3" role="list">
            @foreach ($this->items as $task)
                @php
                    $overdue = CarbonImmutable::parse((string) $task->overdue_since, 'UTC');
                    $next = $task->next_attempt_at ? CarbonImmutable::parse((string) $task->next_attempt_at, 'UTC') : null;
                    $last = $task->occurrences->last();
                @endphp
                <li wire:key="backlog-{{ $task->id }}">
                    <flux:card class="flex flex-col gap-3 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('tasks.show', $task) }}" wire:navigate class="block truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ $task->title }}</a>
                                <flux:text size="sm">
                                    {{ __('Overdue :age', ['age' => LocalClock::relativeDays($overdue, $this->timezone)]) }}
                                    · {{ trans_choice(':count attempt|:count attempts', (int) $task->attempt_count, ['count' => (int) $task->attempt_count]) }}
                                    @if ($last)
                                        · {{ __('last :when', ['when' => LocalClock::dayAndTime($last->scheduled_start_at, $this->timezone)]) }}
                                    @endif
                                </flux:text>
                            </div>
                            @if ($last)
                                <x-state-badge :state="$last->state" :ends-at="$last->scheduled_end_at" />
                            @endif
                        </div>

                        @if ($next)
                            <flux:text size="sm" class="flex items-center gap-1">
                                <flux:icon.calendar class="size-4" />
                                {{ __('Rescheduled for :when', ['when' => LocalClock::dayAndTime($next, $this->timezone)]) }}
                            </flux:text>
                        @endif

                        <div class="grid grid-cols-2 gap-2">
                            <flux:button variant="primary" icon="check" class="min-h-11"
                                wire:click="completeTask('{{ $task->id }}')" wire:loading.attr="disabled" wire:target="completeTask"
                                aria-label="{{ __('Mark :title done', ['title' => $task->title]) }}">
                                {{ __('Done') }}
                            </flux:button>
                            <flux:button icon="calendar" class="min-h-11" :href="route('tasks.show', $task).'#schedule'" wire:navigate
                                aria-label="{{ __('Reschedule :title', ['title' => $task->title]) }}">
                                {{ __('Reschedule') }}
                            </flux:button>
                        </div>
                    </flux:card>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$this->items" />
    @endif
</div>
