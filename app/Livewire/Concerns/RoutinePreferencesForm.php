<?php

namespace App\Livewire\Concerns;

use App\Domain\Accounts\Actions\UpdateUserPreferences;
use App\Models\UserPreference;
use Flux\Flux;

/** Shared form state for Settings and the first-run wizard. */
trait RoutinePreferencesForm
{
    public string $timezone = 'UTC';

    public int $graceMinutes = 15;

    public int $unansweredAfterMinutes = 720;

    public string $preferredActivityStart = '';

    /** @var array<string, list<array{start: string, end: string}>> */
    public array $workWindows = [];

    public bool $quietEnabled = false;

    public string $quietStart = '22:00';

    public string $quietEnd = '07:00';

    /** @var array<string, bool> */
    public array $reviewEnabled = ['morning' => false, 'afternoon' => false, 'evening' => false];

    /** @var array<string, string> */
    public array $reviewTime = ['morning' => '10:45', 'afternoon' => '14:00', 'evening' => '20:00'];

    public bool $completionPrompts = true;

    public bool $dailyReviews = false;

    protected function loadRoutine(): void
    {
        $preference = UserPreference::query()->where('user_id', $this->currentUser()->id)->firstOrFail();
        $this->timezone = $preference->timezone;
        $this->graceMinutes = $preference->grace_minutes;
        $this->unansweredAfterMinutes = $preference->unanswered_after_minutes;
        $this->preferredActivityStart = $preference->preferred_activity_start ?? '';
        $this->workWindows = $preference->work_windows ?? [];
        $this->quietEnabled = $preference->quiet_hours !== null;
        $this->quietStart = $preference->quiet_hours['start'] ?? '22:00';
        $this->quietEnd = $preference->quiet_hours['end'] ?? '07:00';

        foreach (UpdateUserPreferences::REVIEW_KINDS as $kind) {
            $time = $preference->review_times[$kind] ?? null;
            $this->reviewEnabled[$kind] = $time !== null;
            $this->reviewTime[$kind] = $time ?? $this->reviewTime[$kind];
        }

        $this->completionPrompts = (bool) ($preference->notification_preferences['completion_prompts'] ?? true);
        $this->dailyReviews = (bool) ($preference->notification_preferences['daily_reviews'] ?? false);
    }

    public function applyTemplate(string $key): void
    {
        $template = config("routine-templates.{$key}");
        abort_unless(is_array($template) && in_array($key, ['default', 'nunez-week1'], true), 404);

        $this->preferredActivityStart = $template['preferred_activity_start'] ?? '';
        $this->workWindows = $template['work_windows'];
        foreach (UpdateUserPreferences::REVIEW_KINDS as $kind) {
            $time = $template['review_times'][$kind] ?? null;
            $this->reviewEnabled[$kind] = $time !== null;
            if ($time !== null) {
                $this->reviewTime[$kind] = $time;
            }
        }
        $this->dailyReviews = $key !== 'default';
    }

    public function addWindow(string $day): void
    {
        abort_unless(in_array($day, UpdateUserPreferences::WEEKDAYS, true), 422);
        if (count($this->workWindows[$day] ?? []) < 4) {
            $this->workWindows[$day][] = ['start' => '09:00', 'end' => '17:00'];
        }
    }

    public function removeWindow(string $day, int $index): void
    {
        abort_unless(in_array($day, UpdateUserPreferences::WEEKDAYS, true), 422);
        if (isset($this->workWindows[$day][$index])) {
            array_splice($this->workWindows[$day], $index, 1);
        }
    }

    protected function persistRoutine(UpdateUserPreferences $update): void
    {
        $reviewTimes = [];
        foreach (UpdateUserPreferences::REVIEW_KINDS as $kind) {
            $reviewTimes[$kind] = ($this->reviewEnabled[$kind] ?? false) ? ($this->reviewTime[$kind] ?? '') : null;
        }

        $update->handle($this->currentUser(), [
            'timezone' => $this->timezone,
            'grace_minutes' => $this->graceMinutes,
            'unanswered_after_minutes' => $this->unansweredAfterMinutes,
            'preferred_activity_start' => $this->preferredActivityStart ?: null,
            'work_windows' => $this->workWindows,
            'quiet_hours' => $this->quietEnabled ? ['start' => $this->quietStart, 'end' => $this->quietEnd] : null,
            'review_times' => $reviewTimes,
            'notification_preferences' => [
                'completion_prompts' => $this->completionPrompts,
                'daily_reviews' => $this->dailyReviews,
            ],
        ]);

        Flux::toast(variant: 'success', text: __('Routine saved.'));
    }
}
