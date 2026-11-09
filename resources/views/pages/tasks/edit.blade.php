<?php

use App\Livewire\AppPage;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Exceptions\TaskStateConflict;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

new #[Title('Edit task')] class extends AppPage {
    #[Locked]
    public string $taskId = '';
    public string $title = '';
    public string $description = '';
    public int $duration = 15;

    public function mount(Task $task): void
    {
        Gate::authorize('update', $task);

        $this->taskId = $task->id;
        $this->title = $task->title;
        $this->description = (string) $task->description;
        $this->duration = $task->duration_minutes;
    }

    public function save(UpdateTask $updateTask): void
    {
        $task = Task::query()->findOrFail($this->taskId);
        Gate::authorize('update', $task);

        $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'duration' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        try {
            $updateTask->handle($task, [
                'title' => $this->title,
                'description' => $this->description ?: null,
                'duration_minutes' => $this->duration,
            ], $this->currentUser());
        } catch (TaskStateConflict $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Saved.'));
        $this->redirectRoute('tasks.show', ['task' => $task->id], navigate: true);
    }
}; ?>

<div class="mx-auto flex w-full max-w-xl flex-col gap-6">
    <header>
        <flux:heading size="xl" level="1">{{ __('Edit task') }}</flux:heading>
    </header>

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:input wire:model="title" :label="__('What will you do?')" required maxlength="200" />
        <flux:textarea wire:model="description" :label="__('Notes')" rows="3" />
        <flux:input wire:model="duration" type="number" min="1" max="1440" inputmode="numeric" :label="__('Minutes')" class="max-w-32" />

        <div class="flex gap-3">
            <flux:button type="submit" variant="primary" class="min-h-11" wire:loading.attr="disabled" wire:target="save">{{ __('Save') }}</flux:button>
            <flux:button :href="route('tasks.show', $taskId)" variant="ghost" class="min-h-11" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</div>
