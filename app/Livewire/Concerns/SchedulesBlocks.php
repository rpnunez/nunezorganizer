<?php

namespace App\Livewire\Concerns;

use Carbon\CarbonImmutable;

/**
 * Local date/start/end fields for planning a block (native date/time inputs work
 * well on phones). Values are the user's wall-clock time in their own timezone.
 */
trait SchedulesBlocks
{
    public string $date = '';

    public string $start = '';

    public string $end = '';

    protected function defaultBlock(string $timezone, int $durationMinutes): void
    {
        // Next quarter hour, local.
        $now = now()->setTimezone($timezone);
        $start = $now->addMinutes(15 - ($now->minute % 15))->second(0);

        $this->date = $start->format('Y-m-d');
        $this->start = $start->format('H:i');
        $this->end = $start->addMinutes($durationMinutes)->format('H:i');
    }

    /**
     * @return array<string, mixed>
     */
    protected function blockRules(string $prefix = ''): array
    {
        return [
            $prefix.'date' => ['required', 'date_format:Y-m-d'],
            $prefix.'start' => ['required', 'date_format:H:i'],
            $prefix.'end' => ['required', 'date_format:H:i', 'after:'.$prefix.'start'],
        ];
    }

    /**
     * @return array{starts_at: string, ends_at: string}
     */
    protected function blockTimes(string $date, string $start, string $end): array
    {
        return ['starts_at' => "{$date} {$start}", 'ends_at' => "{$date} {$end}"];
    }

    protected function endFromDuration(string $start, int $minutes): string
    {
        return CarbonImmutable::createFromFormat('H:i', $start)?->addMinutes($minutes)->format('H:i') ?? $start;
    }
}
