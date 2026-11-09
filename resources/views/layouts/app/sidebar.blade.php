<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group class="grid">
                    <flux:sidebar.item icon="sun" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Today') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="inbox-stack" :href="route('backlog')" :current="request()->routeIs('backlog')" wire:navigate>
                        {{ __('Backlog') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="list-bullet" :href="route('tasks.index')" :current="request()->routeIs('tasks.*') && ! request()->routeIs('tasks.create')" wire:navigate>
                        {{ __('Tasks') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="chart-bar" :href="route('progress')" :current="request()->routeIs('progress')" wire:navigate>{{ __('Progress') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="clock" :href="route('history')" :current="request()->routeIs('history')" wire:navigate>{{ __('History') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="plus-circle" :href="route('tasks.create')" :current="request()->routeIs('tasks.create')" wire:navigate>
                        {{ __('New task') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        {{-- Bottom tab bar on phones (the sidebar is collapsed there). --}}
        <nav aria-label="{{ __('Main') }}" class="fixed inset-x-0 bottom-0 z-30 border-t border-zinc-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden dark:border-zinc-700 dark:bg-zinc-900/95">
            <ul class="grid grid-cols-4">
                @foreach ([
                    ['route' => 'dashboard', 'match' => 'dashboard', 'icon' => 'sun', 'label' => __('Today')],
                    ['route' => 'backlog', 'match' => 'backlog', 'icon' => 'inbox-stack', 'label' => __('Backlog')],
                    ['route' => 'tasks.index', 'match' => 'tasks.index', 'icon' => 'list-bullet', 'label' => __('Tasks')],
                            ['route' => 'routine.edit', 'match' => 'routine.edit', 'icon' => 'cog', 'label' => __('Settings')],
                ] as $tab)
                    @php($active = request()->routeIs($tab['match']))
                    <li>
                        <a href="{{ route($tab['route']) }}" wire:navigate
                           @if ($active) aria-current="page" @endif
                           class="flex min-h-14 flex-col items-center justify-center gap-0.5 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-accent {{ $active ? 'text-accent-content' : 'text-zinc-500 dark:text-zinc-400' }}">
                            <flux:icon :name="$tab['icon']" variant="{{ $active ? 'solid' : 'outline' }}" class="size-6" />
                            <span>{{ $tab['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
