<x-layouts::app.sidebar :title="$title ?? null">
    {{-- Bottom padding keeps content clear of the phone tab bar. --}}
    <flux:main class="pb-24 lg:pb-8">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
