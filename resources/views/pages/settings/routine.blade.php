<?php

use App\Domain\Accounts\Actions\UpdateUserPreferences;
use App\Livewire\RoutinePage;
use Livewire\Attributes\Title;

new #[Title('Routine settings')] class extends RoutinePage {

    public function mount(): void
    {
        $this->loadRoutine();
    }

    public function save(UpdateUserPreferences $update): void
    {
        $this->persistRoutine($update);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Routine')" :subheading="__('Choose when you plan, check in and receive reminders.')">
        <div class="mb-5 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading level="3">{{ __('Start with a template') }}</flux:heading>
            <flux:text size="sm" class="mt-1">{{ __('Templates only fill this form. Review and save your choices below.') }}</flux:text>
            <div class="mt-3 flex flex-wrap gap-2">
                <flux:button wire:click="applyTemplate('default')" class="min-h-11">{{ __('Start from scratch') }}</flux:button>
                <flux:button wire:click="applyTemplate('nunez-week1')" class="min-h-11">{{ __('Week one: 7 PM') }}</flux:button>
            </div>
        </div>

        <form wire:submit="save" class="space-y-6">
            @include('pages.settings.routine-fields')
            <flux:button type="submit" variant="primary" class="min-h-11" wire:loading.attr="disabled" wire:target="save">{{ __('Save routine') }}</flux:button>
        </form>
    </x-pages::settings.layout>
</section>
