<?php

use App\Domain\Reviews\WeeklyStats;
use App\Livewire\AppPage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('Progress')] class extends AppPage {
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
</div>
