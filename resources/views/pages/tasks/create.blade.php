<?php

use App\Livewire\SchedulingPage;
use App\Domain\Tasks\Actions\CreateTask;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;

new #[Title('New task')] class extends SchedulingPage {
    public string $title = '';
    public string $description = '';
    public int $duration = 15;
    public bool $schedule = true;

    public function mount(): void
    {
        $this->defaultBlock($this->userTimezone(), $this->duration);
    }

    public function updatedDuration(): void
    {
        $this->duration = max(1, min(1440, (int) $this->duration));

        if ($this->start !== '') {
            $this->end = $this->endFromDuration($this->start, $this->duration);
        }
    }

    public function updatedStart(): void
    {
        $this->updatedDuration();
    }

    public function save(CreateTask $createTask): void
    {
        Gate::authorize('create', Task::class);

        // A double tap replays: the second submit finds the first result and just navigates.
        $key = 'web-create:'.$this->currentUser()->id.':'.$this->nonce;
        if ($existing = Cache::get($key)) {
            $this->redirectRoute('tasks.show', ['task' => $existing], navigate: true);

            return;
        }

        $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'duration' => ['required', 'integer', 'min:1', 'max:1440'],
            ...($this->schedule ? $this->blockRules() : []),
        ]);

        ['task' => $task] = $createTask->handle(
            $this->currentUser(),
            ['title' => $this->title, 'description' => $this->description ?: null, 'duration_minutes' => $this->duration],
            $this->schedule ? [...$this->blockTimes($this->date, $this->start, $this->end), 'timezone' => $this->userTimezone()] : null,
        );

        Cache::put($key, $task->id, now()->addHour());
        Flux::toast(variant: 'success', text: __('Task created.'));
        $this->redirectRoute('tasks.show', ['task' => $task], navigate: true);
    }
}; ?>

<div class="mx-auto flex w-full max-w-xl flex-col gap-6">
    <header>
        <flux:heading size="xl" level="1">{{ __('New task') }}</flux:heading>
        <flux:text>{{ __('Keep it small: something you can finish in one short block.') }}</flux:text>
    </header>

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:input wire:model="title" :label="__('What will you do?')" :placeholder="__('Organize my desk')" required autofocus maxlength="200" />

        <flux:textarea wire:model="description" :label="__('Notes')" :placeholder="__('Optional')" rows="2" />

        <flux:input wire:model.live.debounce.400ms="duration" type="number" min="1" max="1440" inputmode="numeric" :label="__('Minutes')" class="max-w-32" />

        <flux:fieldset>
            <flux:legend>{{ __('When') }}</flux:legend>
            <flux:switch wire:model.live="schedule" :label="__('Schedule a block now')" />

            @if ($schedule)
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <flux:input wire:model="date" type="date" :label="__('Date')" required />
                    <flux:input wire:model.live="start" type="time" :label="__('Start')" required />
                    <flux:input wire:model="end" type="time" :label="__('End')" required />
                </div>
                <flux:text size="sm" class="mt-2">{{ __('Times are in :tz. You\'ll be asked "Did you finish?" when the block ends.', ['tz' => $this->userTimezone()]) }}</flux:text>
            @else
                <flux:text size="sm" class="mt-2">{{ __('You can schedule it later from the task page.') }}</flux:text>
            @endif
        </flux:fieldset>

        <div class="flex gap-3">
            <flux:button type="submit" variant="primary" class="min-h-11 flex-1 sm:flex-none" wire:loading.attr="disabled" wire:target="save" data-test="create-task">
                {{ __('Create task') }}
            </flux:button>
            <flux:button :href="route('tasks.index')" variant="ghost" class="min-h-11" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</div>
