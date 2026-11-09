<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => __('Welcome')])
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <main class="mx-auto flex min-h-screen max-w-xl flex-col justify-center gap-8 px-6 py-12">
            <div class="flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
                    <x-app-logo-icon class="size-6 fill-current text-white dark:text-black" />
                </span>
                <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ config('app.name') }}</h1>
            </div>

            <p class="text-lg text-zinc-600 dark:text-zinc-300">
                {{ __('Make small, time-bound commitments. Get a check-in when each block ends. Record an honest outcome, and keep unfinished work visible until it is done.') }}
            </p>

            <div class="flex flex-wrap gap-3">
                @auth
                    <flux:button :href="route('dashboard')" variant="primary" wire:navigate>{{ __('Go to dashboard') }}</flux:button>
                @else
                    <flux:button :href="route('login')" variant="primary" wire:navigate>{{ __('Log in') }}</flux:button>
                @endauth
            </div>

            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Access is by invitation only.') }}</p>
        </main>
        @fluxScripts
    </body>
</html>
