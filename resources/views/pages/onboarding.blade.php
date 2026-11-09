<?php

use App\Domain\Accounts\Actions\UpdateUserPreferences;
use App\Domain\Tasks\Actions\CreateTask;
use App\Livewire\RoutinePage;
use App\Models\Task;
use App\Models\UserPreference;
use App\Rules\ValidIanaTimezone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

new #[Title('Set up your routine')] class extends RoutinePage {

    public int $step = 1;
    public string $firstTask = '';

    #[Locked]
    public string $submissionKey = '';

    public function mount(): void
    {
        $this->loadRoutine();
        $this->submissionKey = (string) Str::ulid();
    }

    public function next(): void
    {
        $this->validate(['timezone' => ['required', 'string', new ValidIanaTimezone]]);
        $this->step = 2;
    }

    public function saveRoutine(UpdateUserPreferences $update): void
    {
        $this->persistRoutine($update);
        $this->step = 3;
    }

    public function finish(UpdateUserPreferences $update, CreateTask $create): void
    {
        $this->validate(['firstTask' => ['required', 'string', 'max:200']]);
        Gate::authorize('create', Task::class);
        $this->persistRoutine($update);

        $key = 'web-onboarding:'.$this->currentUser()->id.':'.$this->submissionKey;
        $taskId = Cache::lock($key.':lock', 10)->block(5, function () use ($key, $create): string {
            if ($existing = Cache::get($key)) {
                return (string) $existing;
            }

            $taskId = DB::transaction(function () use ($create): string {
                $task = $create->handle($this->currentUser(), ['title' => $this->firstTask, 'duration_minutes' => 15])['task'];
                UserPreference::query()->where('user_id', $this->currentUser()->id)
                    ->lockForUpdate()->firstOrFail()->forceFill(['onboarding_completed_at' => now()])->save();

                return $task->id;
            });
            Cache::put($key, $taskId, now()->addHour());

            return $taskId;
        });

        $this->redirectRoute('tasks.show', ['task' => $taskId], navigate: true);
    }
}; ?>

<div class="mx-auto flex w-full max-w-xl flex-col gap-6">
    <header>
        <flux:heading size="xl" level="1">{{ __('Set up your routine') }}</flux:heading>
        <flux:text>{{ __('Step :step of 3', ['step' => $step]) }}</flux:text>
    </header>

    @if ($step === 1)
        <form wire:submit="next" class="space-y-4">
            <flux:heading level="2">{{ __('Your timezone') }}</flux:heading>
            <flux:text>{{ __('Your day and review times follow this timezone, including daylight saving changes.') }}</flux:text>
            <label for="onboarding-timezone" class="block text-sm font-medium">{{ __('Timezone') }}</label>
            <input id="onboarding-timezone" list="onboarding-timezones" wire:model="timezone" required
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900" />
            <datalist id="onboarding-timezones">
                @foreach (DateTimeZone::listIdentifiers() as $zone)
                    <option value="{{ $zone }}"></option>
                @endforeach
            </datalist>
            <flux:button type="button" variant="ghost" class="min-h-11"
                x-on:click="$wire.set('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone)">{{ __('Use this device’s timezone') }}</flux:button>
            @error('timezone') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
            <flux:button type="submit" variant="primary" class="min-h-11">{{ __('Continue') }}</flux:button>
        </form>
    @elseif ($step === 2)
        <div class="space-y-5">
            <flux:heading level="2">{{ __('Choose your routine') }}</flux:heading>
            <flux:text>{{ __('The week-one template is optional. Nothing is saved until you select Save and continue.') }}</flux:text>
            <div class="flex flex-wrap gap-2">
                <flux:button wire:click="applyTemplate('default')" class="min-h-11">{{ __('Start from scratch') }}</flux:button>
                <flux:button wire:click="applyTemplate('nunez-week1')" class="min-h-11">{{ __('Week one: 7 PM') }}</flux:button>
            </div>
            <form wire:submit="saveRoutine" class="space-y-6">
                @include('pages.settings.routine-fields')
                <flux:button type="submit" variant="primary" class="min-h-11">{{ __('Save and continue') }}</flux:button>
            </form>
        </div>
    @else
        <form wire:submit="finish" class="space-y-5">
            <flux:heading level="2">{{ __('Create your first task') }}</flux:heading>
            <flux:text>{{ __('Give a small task a name. You can schedule its first block on the task page.') }}</flux:text>
            <flux:input wire:model="firstTask" :label="__('Task name')" required maxlength="200" />
            <flux:button type="submit" variant="primary" class="min-h-11" wire:loading.attr="disabled" wire:target="finish">{{ __('Create task and finish') }}</flux:button>
            <flux:text size="sm">{{ __('Telegram connection comes later. You can use the app fully from the browser now.') }}</flux:text>
        </form>
    @endif
</div>
