@props(['state', 'endsAt' => null])

@php
    $value = $state instanceof \BackedEnum ? $state->value : (string) $state;

    // A scheduled block whose end has passed is waiting for an answer, not "upcoming".
    if ($value === 'scheduled' && $endsAt !== null && $endsAt->lessThanOrEqualTo(now())) {
        $value = 'awaiting_response';
    }
    [$color, $label] = match ($value) {
        'scheduled' => ['zinc', __('Scheduled')],
        'awaiting_response' => ['amber', __('Waiting for answer')],
        'finished' => ['green', __('Done')],
        'missed' => ['red', __('Not done')],
        'unanswered' => ['orange', __('No answer')],
        'cancelled' => ['zinc', __('Cancelled')],
        'open' => ['sky', __('Open')],
        'completed' => ['green', __('Completed')],
        default => ['zinc', ucfirst(str_replace('_', ' ', $value))],
    };
@endphp

{{-- Color is never the only signal: the label always states the status. --}}
<flux:badge size="sm" :color="$color" {{ $attributes }}>{{ $label }}</flux:badge>
