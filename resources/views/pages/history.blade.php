<?php

use App\Livewire\AppPage;
use App\Models\TaskOccurrence;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('History')] class extends AppPage {
    use WithPagination;

    #[Url]
    public string $state = '';

    #[Url]
    public string $date = '';

    #[Computed]
    public function items()
    {
        $timezone = $this->userTimezone();
        $query = TaskOccurrence::query()
            ->whereHas('task', fn ($q) => $q->where('owner_user_id', $this->currentUser()->id))
            ->with('task')->orderByDesc('scheduled_start_at');

        if (in_array($this->state, ['scheduled', 'awaiting_response', 'finished', 'missed', 'unanswered', 'cancelled'], true)) {
            $query->where('state', $this->state);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            $day = \App\Domain\Scheduling\Queries\LocalDay::of($this->date, $timezone);
            $query->where('scheduled_start_at', '>=', $day->startUtc)
                ->where('scheduled_start_at', '<', $day->endUtc);
        }

        return $query->paginate(20);
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
    <header><flux:heading size="xl" level="1">{{ __('History') }}</flux:heading>
        <flux:text>{{ __('Every scheduled attempt, including missed and unanswered blocks.') }}</flux:text></header>
    <div class="flex flex-wrap gap-3">
        <label class="text-sm">{{ __('Date') }} <input type="date" wire:model.live="date" class="rounded border p-2 dark:bg-zinc-800" /></label>
        <label class="text-sm">{{ __('State') }}
            <select wire:model.live="state" class="rounded border p-2 dark:bg-zinc-800">
                <option value="">{{ __('All') }}</option>
                @foreach (['scheduled', 'awaiting_response', 'finished', 'missed', 'unanswered', 'cancelled'] as $option)
                    <option value="{{ $option }}">{{ ucfirst(str_replace('_', ' ', $option)) }}</option>
                @endforeach
            </select>
        </label>
    </div>
    <ul class="flex flex-col gap-3" role="list">
        @forelse ($this->items as $block)
            <li wire:key="history-{{ $block->id }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <a href="{{ route('tasks.show', $block->task_id) }}" wire:navigate class="font-medium">{{ $block->task?->title }}</a>
                <div class="text-sm text-zinc-500">{{ $block->scheduled_start_at->setTimezone($this->userTimezone())->format('M j, g:i A') }} · {{ str_replace('_', ' ', $block->state->value) }}</div>
            </li>
        @empty
            <li>{{ __('No attempts match these filters.') }}</li>
        @endforelse
    </ul>
    {{ $this->items->links() }}
</div>
