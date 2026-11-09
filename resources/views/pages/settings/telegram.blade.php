<?php

use App\Integrations\Telegram\ConfirmPairing;
use App\Integrations\Telegram\CreatePairingCode;
use App\Integrations\Telegram\UnlinkTelegram;
use App\Livewire\AppPage;
use App\Models\PairingCode;
use App\Models\TelegramLink;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('Telegram settings')] class extends AppPage {
    public string $code = '';

    #[Computed]
    public function link(): ?TelegramLink
    {
        return TelegramLink::query()->where('user_id', $this->currentUser()->id)->first();
    }

    #[Computed]
    public function pending(): ?PairingCode
    {
        return PairingCode::query()->where('user_id', $this->currentUser()->id)
            ->whereNull('used_at')->latest()->first();
    }

    public function generate(CreatePairingCode $create): void
    {
        $this->code = $create->handle($this->currentUser());
        unset($this->pending);
    }

    public function confirm(ConfirmPairing $confirm): void
    {
        $confirm->handle($this->currentUser(), $this->code);
        $this->code = '';
        unset($this->link, $this->pending);
        Flux::toast(variant: 'success', text: __('Telegram chat connected.'));
    }

    public function unlink(UnlinkTelegram $unlink): void
    {
        $unlink->handle($this->currentUser());
        unset($this->link);
        Flux::toast(text: __('Telegram chat disconnected.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')
    <x-pages::settings.layout :heading="__('Telegram')" :subheading="__('Connect one private chat for completion prompts.')">
        @if ($this->link?->status === 'active')
            <flux:text>{{ __('Connected to Telegram user :id.', ['id' => $this->link->telegram_user_id]) }}</flux:text>
            <flux:button wire:click="unlink" wire:confirm="{{ __('Disconnect this Telegram chat?') }}" class="mt-4 min-h-11">{{ __('Disconnect') }}</flux:button>
        @else
            <flux:text>{{ __('Generate a ten-minute code, open the bot in a private chat, then confirm the chat here.') }}</flux:text>
            <flux:button wire:click="generate" class="mt-4 min-h-11">{{ __('Generate pairing code') }}</flux:button>
            @if ($code !== '')
                <div class="mt-4 space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:text>{{ __('Pairing code: :code', ['code' => $code]) }}</flux:text>
                    @if (config('services.telegram.username'))
                        <flux:button :href="'https://t.me/'.config('services.telegram.username').'?start='.$code" target="_blank" class="min-h-11">{{ __('Open Telegram bot') }}</flux:button>
                    @endif
                    @if ($this->pending?->claimed_telegram_user_id)
                        <flux:text>{{ __('Telegram user :id requested this link. Confirm only if this is your chat.', ['id' => $this->pending->claimed_telegram_user_id]) }}</flux:text>
                    @endif
                    <flux:button wire:click="confirm" variant="primary" class="min-h-11">{{ __('Confirm chat') }}</flux:button>
                    @error('pairing') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
                </div>
            @endif
        @endif
    </x-pages::settings.layout>
</section>
