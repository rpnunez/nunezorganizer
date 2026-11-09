<flux:fieldset class="space-y-4">
    <flux:legend>{{ __('Your local schedule') }}</flux:legend>
    <div>
        <label for="routine-timezone" class="block text-sm font-medium">{{ __('Timezone') }}</label>
        <input id="routine-timezone" list="routine-timezones" wire:model="timezone" autocomplete="off" required
            class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-zinc-900 focus-visible:outline-2 focus-visible:outline-accent dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
        <datalist id="routine-timezones">
            @foreach (DateTimeZone::listIdentifiers() as $zone)
                <option value="{{ $zone }}"></option>
            @endforeach
        </datalist>
        <flux:button type="button" variant="ghost" size="sm" class="mt-2 min-h-11"
            x-on:click="$wire.set('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone)">
            {{ __('Use this device’s timezone') }}
        </flux:button>
        @error('timezone') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
    </div>
    <flux:input wire:model="preferredActivityStart" type="time" :label="__('Preferred activity start (optional)')" />
    @error('preferred_activity_start') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
    <flux:input wire:model="graceMinutes" type="number" min="0" max="240" :label="__('Grace period (minutes)')" />
    @error('grace_minutes') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
    <flux:input wire:model="unansweredAfterMinutes" type="number" min="15" max="10080" :label="__('Mark unanswered after (minutes)')" />
    @error('unanswered_after_minutes') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
</flux:fieldset>

<flux:fieldset class="space-y-3">
    <flux:legend>{{ __('Work windows by weekday') }}</flux:legend>
    <flux:text size="sm">{{ __('Add up to four windows per day. These times stay local when daylight saving time changes.') }}</flux:text>
    @foreach (\App\Domain\Accounts\Actions\UpdateUserPreferences::WEEKDAYS as $day)
        <div wire:key="day-{{ $day }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
            <div class="flex items-center justify-between gap-2">
                <flux:heading level="3" size="sm">{{ ucfirst($day) }}</flux:heading>
                <flux:button type="button" size="sm" class="min-h-11" wire:click="addWindow('{{ $day }}')">{{ __('Add window') }}</flux:button>
            </div>
            @foreach ($workWindows[$day] ?? [] as $index => $window)
                <div wire:key="window-{{ $day }}-{{ $index }}" class="mt-2 flex flex-wrap items-end gap-2">
                    <flux:input wire:model="workWindows.{{ $day }}.{{ $index }}.start" type="time" :label="__('Start')" />
                    <flux:input wire:model="workWindows.{{ $day }}.{{ $index }}.end" type="time" :label="__('End')" />
                    <flux:button type="button" size="sm" class="min-h-11" wire:click="removeWindow('{{ $day }}', {{ $index }})">{{ __('Remove') }}</flux:button>
                </div>
            @endforeach
            @error('work_windows.'.$day) <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
        </div>
    @endforeach
</flux:fieldset>

<flux:fieldset class="space-y-3">
    <flux:legend>{{ __('Quiet hours') }}</flux:legend>
    <flux:switch wire:model.live="quietEnabled" :label="__('Use quiet hours')" />
    @if ($quietEnabled)
        <div class="flex flex-wrap gap-3">
            <flux:input wire:model="quietStart" type="time" :label="__('From')" />
            <flux:input wire:model="quietEnd" type="time" :label="__('Until')" />
        </div>
        @error('quiet_hours.start') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
        @error('quiet_hours.end') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
    @endif
</flux:fieldset>

<flux:fieldset class="space-y-3">
    <flux:legend>{{ __('Daily reviews') }}</flux:legend>
    <flux:switch wire:model.live="dailyReviews" :label="__('Enable daily reviews')" />
    @foreach (\App\Domain\Accounts\Actions\UpdateUserPreferences::REVIEW_KINDS as $kind)
        <div wire:key="review-{{ $kind }}" class="flex flex-wrap items-end gap-3">
            <flux:switch wire:model.live="reviewEnabled.{{ $kind }}" :label="__(ucfirst($kind))" />
            @if ($reviewEnabled[$kind] ?? false)
                <flux:input wire:model="reviewTime.{{ $kind }}" type="time" :label="__('Local time')" />
            @endif
            @error('review_times.'.$kind) <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
        </div>
    @endforeach
</flux:fieldset>

<flux:fieldset>
    <flux:legend>{{ __('Notifications') }}</flux:legend>
    <flux:switch wire:model="completionPrompts" :label="__('End-of-block completion prompts')" />
    <flux:text size="sm">{{ __('Telegram delivery becomes available after you connect a private chat in a later release.') }}</flux:text>
</flux:fieldset>
