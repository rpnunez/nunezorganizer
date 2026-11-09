<?php

use App\Livewire\TaskActionsPage;
use App\Domain\Scheduling\Queries\BacklogQuery;
use App\Domain\Scheduling\Queries\LocalDay;
use App\Domain\Scheduling\Queries\TodayQuery;
use App\Enums\OccurrenceState;
use App\Support\LocalClock;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('Today')] class extends TaskActionsPage {
    #[Computed]
    public function timezone(): string
    {
        return $this->userTimezone();
    }

    #[Computed]
    public function day(): LocalDay
    {
        return LocalDay::today($this->timezone);
    }

    #[Computed]
    public function pending()
    {
        return app(TodayQuery::class)->pendingResponses($this->currentUser());
    }

    #[Computed]
    public function blocks()
    {
        return app(TodayQuery::class)->blocks($this->currentUser(), $this->day);
    }

    #[Computed]
    public function backlogCount(): int
    {
        return app(BacklogQuery::class)->count($this->currentUser(), $this->day);
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-8">
    <header class="flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Today') }}</flux:heading>
            <flux:text>{{ now()->setTimezone($this->timezone)->format('l, F j') }} · {{ $this->timezone }}</flux:text>
        </div>
        <flux:button :href="route('tasks.create')" icon="plus" variant="primary" size="sm" wire:navigate>{{ __('New task') }}</flux:button>
    </header>

    {{-- 1. Blocks waiting for an honest answer --}}
    @if ($this->pending->isNotEmpty())
        <section aria-labelledby="pending-heading" class="flex flex-col gap-3">
            <flux:heading level="2" id="pending-heading">{{ __('Did you finish?') }}</flux:heading>

            <ul class="flex flex-col gap-3" role="list">
                @foreach ($this->pending as $block)
                    <li wire:key="pending-{{ $block->id }}">
                        <flux:card class="flex flex-col gap-3 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <a href="{{ route('tasks.show', $block->task_id) }}" wire:navigate class="block truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ $block->task?->title }}</a>
                                    <flux:text size="sm">{{ LocalClock::range($block->scheduled_start_at, $block->scheduled_end_at, $this->timezone) }}</flux:text>
                                </div>
                                <x-state-badge :state="$block->state" :ends-at="$block->scheduled_end_at" />
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <flux:button variant="primary" icon="check" class="min-h-11"
                                    wire:click="respond('{{ $block->id }}', 'yes')" wire:loading.attr="disabled" wire:target="respond"
                                    aria-label="{{ __('Yes, I finished :title', ['title' => $block->task?->title]) }}">
                                    {{ __('Yes') }}
                                </flux:button>
                                <flux:button icon="x-mark" class="min-h-11"
                                    wire:click="respond('{{ $block->id }}', 'no')" wire:loading.attr="disabled" wire:target="respond"
                                    aria-label="{{ __('No, I did not finish :title', ['title' => $block->task?->title]) }}">
                                    {{ __('No') }}
                                </flux:button>
                            </div>
                        </flux:card>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- 2. Today's plan --}}
    <section aria-labelledby="plan-heading" class="flex flex-col gap-3">
        <flux:heading level="2" id="plan-heading">{{ __('Scheduled today') }}</flux:heading>

        @if ($this->blocks->isEmpty())
            <flux:card class="flex flex-col items-start gap-3 p-6">
                <flux:text>{{ __('Nothing scheduled today.') }}</flux:text>
                <flux:button :href="route('tasks.create')" icon="plus" size="sm" wire:navigate>{{ __('Plan a 15-minute block') }}</flux:button>
            </flux:card>
        @else
            <ul class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" role="list">
                @foreach ($this->blocks as $block)
                    <li wire:key="block-{{ $block->id }}" class="flex items-center justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <a href="{{ route('tasks.show', $block->task_id) }}" wire:navigate class="block truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ $block->task?->title }}</a>
                            <flux:text size="sm">{{ LocalClock::time($block->scheduled_start_at, $this->timezone) }}–{{ LocalClock::time($block->scheduled_end_at, $this->timezone) }}</flux:text>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <x-state-badge :state="$block->state" :ends-at="$block->scheduled_end_at" />
                            @if ($block->state === OccurrenceState::Scheduled && $block->task?->isOpen())
                                <flux:button size="sm" variant="ghost" icon="check" class="min-h-11"
                                    wire:click="completeTask('{{ $block->task_id }}')" wire:loading.attr="disabled" wire:target="completeTask"
                                    aria-label="{{ __('Mark :title done', ['title' => $block->task?->title]) }}" />
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- 3. Carryover --}}
    <section aria-labelledby="backlog-heading">
        <h2 id="backlog-heading" class="sr-only">{{ __('Backlog') }}</h2>
        <a href="{{ route('backlog') }}" wire:navigate
           class="flex min-h-14 items-center justify-between rounded-xl border border-zinc-200 p-4 hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-accent dark:border-zinc-700 dark:hover:bg-zinc-800">
            <span class="font-medium text-zinc-900 dark:text-white">
                @if ($this->backlogCount === 0)
                    {{ __('Backlog is clear') }}
                @else
                    {{ trans_choice(':count unfinished task carried over|:count unfinished tasks carried over', $this->backlogCount, ['count' => $this->backlogCount]) }}
                @endif
            </span>
            <span class="flex items-center gap-2">
                @if ($this->backlogCount > 0)
                    <flux:badge color="red" size="sm">{{ $this->backlogCount }}</flux:badge>
                @endif
                <flux:icon.chevron-right class="size-5 text-zinc-400" />
            </span>
        </a>
    </section>
</div>
