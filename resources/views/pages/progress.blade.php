<?php

use App\Domain\Reviews\WeeklyStats;
use App\Domain\Reviews\RoutinePlanService;
use App\Livewire\AppPage;
use App\Models\RoutinePlan;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('Progress')] class extends AppPage {
    public string $templateKey = 'start-small';
    public int $targetDays = 5;
    public string $preferredTime = '19:00';
    public int $maxBacklog = 2;
    public string $acceptKey = '';

    public function mount(): void
    {
        $this->acceptKey = (string) Str::ulid();
        $plan = RoutinePlan::query()->where('user_id', $this->currentUser()->id)->first();
        if ($plan !== null) {
            $this->targetDays = $plan->settings['target_days'];
            $this->preferredTime = $plan->settings['preferred_time'];
            $this->maxBacklog = $plan->settings['max_backlog'];
        }
    }

    #[Computed]
    public function plan(): ?RoutinePlan
    {
        return RoutinePlan::query()->where('user_id', $this->currentUser()->id)->first();
    }

    #[Computed]
    public function suggestion(): ?array
    {
        return $this->plan === null ? null
            : app(RoutinePlanService::class)->suggest($this->currentUser(), $this->plan);
    }

    public function startPlan(): void
    {
        app(RoutinePlanService::class)->start($this->currentUser(), $this->templateKey, $this->goals());
        unset($this->plan, $this->suggestion);
    }

    public function saveGoals(): void
    {
        if ($this->plan === null) {
            return;
        }
        app(RoutinePlanService::class)->saveGoals($this->currentUser(), $this->plan->id, $this->goals());
        unset($this->plan, $this->suggestion);
    }

    public function acceptSuggestion(): void
    {
        if ($this->plan === null) {
            return;
        }
        app(RoutinePlanService::class)->accept($this->currentUser(), $this->plan->id, $this->acceptKey);
        $this->acceptKey = (string) Str::ulid();
        unset($this->plan, $this->suggestion);
    }

    private function goals(): array
    {
        return ['target_days' => $this->targetDays,
            'preferred_time' => $this->preferredTime, 'max_backlog' => $this->maxBacklog];
    }

    #[Computed]
    public function stats(): array
    {
        $timezone = $this->userTimezone();

        return app(WeeklyStats::class)->forUser($this->currentUser(), now()->setTimezone($timezone)->format('Y-m-d'), $timezone);
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
    <header><flux:heading size="xl" level="1">{{ __('Progress') }}</flux:heading>
        <flux:text>{{ __('Week of :date', ['date' => $this->stats['week_start']]) }} · {{ $this->stats['timezone'] }}</flux:text></header>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        @foreach (['completed' => __('Done'), 'missed' => __('Missed'), 'unanswered' => __('Unanswered'), 'pending' => __('Pending'), 'late' => __('Late')] as $key => $label)
            <flux:card><flux:heading level="2">{{ $label }}</flux:heading>
                <flux:text>{{ $this->stats[$key] }} {{ __('of :count planned blocks', ['count' => $this->stats['planned']]) }}</flux:text></flux:card>
        @endforeach
        <flux:card><flux:heading level="2">{{ __('Open overdue tasks') }}</flux:heading>
            <flux:text>{{ $this->stats['open_overdue_tasks'] }} {{ __('tasks') }}</flux:text></flux:card>
    </div>
    <section aria-label="{{ __('Daily progress') }}" class="flex flex-col gap-2">
        @foreach ($this->stats['days'] as $date => $day)
            <div class="flex items-center gap-3 text-sm"><span class="w-24">{{ $date }}</span>
                <div class="h-3 flex-1 rounded bg-zinc-200 dark:bg-zinc-700"><div class="h-3 rounded bg-emerald-600" style="width: {{ $day['planned'] ? round(100 * $day['completed'] / $day['planned']) : 0 }}%"></div></div>
                <span>{{ $day['completed'] }}/{{ $day['planned'] }}</span></div>
        @endforeach
    </section>
    <a href="{{ route('history') }}" wire:navigate class="underline">{{ __('View attempt history') }}</a>

    <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" aria-label="{{ __('Routine progression') }}">
        <flux:heading level="2">{{ __('Routine progression') }}</flux:heading>
        @if ($this->plan)
            <flux:text>{{ config('progression-templates.'.$this->plan->template_key.'.label') }} · {{ __('Week :week of 4', ['week' => $this->plan->current_week]) }}</flux:text>
            <flux:text>{{ config('progression-templates.'.$this->plan->template_key.'.weeks.'.$this->plan->current_week) }}</flux:text>
            <p class="mt-3 text-sm">{{ $this->suggestion['reason'] }}</p>
            @if ($this->suggestion['can_advance'])
                <flux:button wire:click="acceptSuggestion" variant="primary" class="mt-3 min-h-11">{{ __('Accept next week') }}</flux:button>
            @endif
            <p class="mt-3 text-sm">{{ __('To plan a suggested block, create and confirm it yourself.') }} <a href="{{ route('tasks.create') }}" wire:navigate class="underline">{{ __('New task') }}</a></p>
        @else
            <flux:text>{{ __('Choose a starting template. Suggestions never schedule work or advance your week automatically.') }}</flux:text>
            <label class="mt-3 block text-sm">{{ __('Template') }}
                <select wire:model="templateKey" class="w-full rounded border p-2 dark:bg-zinc-800">
                    @foreach (config('progression-templates') as $key => $template)
                        <option value="{{ $key }}">{{ $template['label'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <div class="mt-3 grid gap-3 sm:grid-cols-3">
            <label class="text-sm">{{ __('Completed days goal') }}<input type="number" min="1" max="7" wire:model="targetDays" class="w-full rounded border p-2 dark:bg-zinc-800" /></label>
            <label class="text-sm">{{ __('Preferred time') }}<input type="time" wire:model="preferredTime" class="w-full rounded border p-2 dark:bg-zinc-800" /></label>
            <label class="text-sm">{{ __('Maximum backlog') }}<input type="number" min="0" max="20" wire:model="maxBacklog" class="w-full rounded border p-2 dark:bg-zinc-800" /></label>
        </div>
        @error('template') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        @error('plan') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        @if ($this->plan)
            <flux:button wire:click="saveGoals" class="mt-3 min-h-11">{{ __('Save goals') }}</flux:button>
        @else
            <flux:button wire:click="startPlan" variant="primary" class="mt-3 min-h-11">{{ __('Start routine') }}</flux:button>
        @endif
    </section>
</div>
