<?php

use App\Livewire\AppPage;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Support\LocalClock;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('Tasks')] class extends AppPage {
    use WithPagination;

    #[Url]
    public string $status = 'open';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function timezone(): string
    {
        return $this->userTimezone();
    }

    #[Computed]
    public function tasks()
    {
        $status = TaskStatus::tryFrom($this->status) ?? TaskStatus::Open;

        return Task::query()
            ->where('owner_user_id', $this->currentUser()->id)
            ->where('status', $status)
            ->withCount('occurrences')
            ->orderByDesc('updated_at')
            ->paginate(20);
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
    <header class="flex items-end justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Tasks') }}</flux:heading>
        <flux:button :href="route('tasks.create')" icon="plus" variant="primary" size="sm" wire:navigate>{{ __('New task') }}</flux:button>
    </header>

    <flux:radio.group wire:model.live="status" variant="segmented" :label="__('Show')" class="max-w-md">
        <flux:radio value="open" :label="__('Open')" />
        <flux:radio value="completed" :label="__('Completed')" />
        <flux:radio value="cancelled" :label="__('Cancelled')" />
    </flux:radio.group>

    @if ($this->tasks->isEmpty())
        <flux:card class="p-6">
            <flux:text>{{ $status === 'open' ? __('No open tasks. Create one to get started.') : __('Nothing here yet.') }}</flux:text>
        </flux:card>
    @else
        <ul class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" role="list">
            @foreach ($this->tasks as $task)
                <li wire:key="task-{{ $task->id }}">
                    <a href="{{ route('tasks.show', $task) }}" wire:navigate class="flex min-h-14 items-center justify-between gap-3 p-4 hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-accent dark:hover:bg-zinc-800">
                        <span class="min-w-0">
                            <span class="block truncate font-medium text-zinc-900 dark:text-white">{{ $task->title }}</span>
                            <flux:text size="sm">
                                {{ trans_choice(':count min|:count min', $task->duration_minutes, ['count' => $task->duration_minutes]) }}
                                · {{ trans_choice(':count attempt|:count attempts', $task->occurrences_count, ['count' => $task->occurrences_count]) }}
                                @if ($task->completed_at)
                                    · {{ __('done :when', ['when' => LocalClock::dayAndTime($task->completed_at, $this->timezone)]) }}
                                @endif
                            </flux:text>
                        </span>
                        <span class="flex items-center gap-2">
                            <x-state-badge :state="$task->status" />
                            <flux:icon.chevron-right class="size-5 text-zinc-400" />
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$this->tasks" />
    @endif
</div>
