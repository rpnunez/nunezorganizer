<?php

namespace App\Domain\Reviews;

use App\Domain\Accounts\TenantContext;
use App\Domain\Audit\Audit;
use App\Models\RoutinePlan;
use App\Models\RoutinePlanAdvancement;
use App\Models\User;
use App\Models\UserPreference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RoutinePlanService
{
    public function __construct(private readonly TenantContext $tenant, private readonly WeeklyStats $stats,
        private readonly ProgressionAdvisor $advisor) {}

    /** @param array<string, mixed> $settings */
    public function start(User $user, string $templateKey, array $settings): RoutinePlan
    {
        $this->assertOwner($user);
        if (! array_key_exists($templateKey, config('progression-templates', []))) {
            throw ValidationException::withMessages(['template' => 'Choose a valid routine template.']);
        }
        $settings = $this->validatedSettings($settings);
        $timezone = $this->timezone($user);
        $monday = CarbonImmutable::instance(now())->setTimezone($timezone)->startOfWeek()->format('Y-m-d');

        return DB::transaction(function () use ($user, $templateKey, $settings, $monday): RoutinePlan {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = RoutinePlan::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            $plan = RoutinePlan::query()->create(['user_id' => $user->id,
                'template_key' => $templateKey, 'current_week' => 1, 'started_on' => $monday,
                'status' => 'active', 'settings' => $settings]);
            Audit::record('routine_plan.started', $plan, ['template' => $templateKey], actor: $user);

            return $plan;
        });
    }

    /** @param array<string, mixed> $settings */
    public function saveGoals(User $user, string $planId, array $settings): RoutinePlan
    {
        $this->assertOwner($user);
        $settings = $this->validatedSettings($settings);

        return DB::transaction(function () use ($user, $planId, $settings): RoutinePlan {
            $plan = RoutinePlan::query()->whereKey($planId)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $plan->forceFill(['settings' => $settings]);
            if ($plan->isDirty('settings')) {
                $plan->save();
                Audit::record('routine_plan.goals_updated', $plan, [], actor: $user);
            }

            return $plan;
        });
    }

    public function accept(User $user, string $planId, string $idempotencyKey): RoutinePlan
    {
        $this->assertOwner($user);

        return DB::transaction(function () use ($user, $planId, $idempotencyKey): RoutinePlan {
            $plan = RoutinePlan::query()->whereKey($planId)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if (RoutinePlanAdvancement::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                return $plan;
            }
            if ($plan->status !== 'active') {
                throw ValidationException::withMessages(['plan' => 'This routine plan is not active.']);
            }
            $suggestion = $this->suggest($user, $plan);
            if (! $suggestion['can_advance']) {
                throw ValidationException::withMessages(['plan' => $suggestion['reason']]);
            }

            $fromWeek = $plan->current_week;
            RoutinePlanAdvancement::query()->create(['routine_plan_id' => $plan->id,
                'user_id' => $user->id, 'from_week' => $fromWeek,
                'idempotency_key' => $idempotencyKey, 'accepted_at' => now()]);
            $plan->forceFill(['current_week' => $fromWeek + 1])->save();
            Audit::record('routine_plan.advanced', $plan, ['from_week' => $fromWeek, 'to_week' => $plan->current_week], actor: $user);

            return $plan;
        });
    }

    /** @return array{can_advance: bool, reason: string, completed_days: int, target_days: int, next_week: int|null} */
    public function suggest(User $user, RoutinePlan $plan, ?string $todayLocal = null): array
    {
        $this->assertOwner($user);
        abort_unless($plan->user_id === $user->id && $plan->account_id === $this->tenant->accountId(), 404);
        $timezone = $this->timezone($user);
        $weekStart = $plan->started_on->copy()->addWeeks($plan->current_week - 1)->format('Y-m-d');
        $stats = $this->stats->forUser($user, $weekStart, $timezone);
        $today = $todayLocal ?? now()->setTimezone($timezone)->format('Y-m-d');

        return $this->advisor->advise($plan->current_week,
            $plan->started_on->format('Y-m-d'), $today, $stats['days'],
            $stats['open_overdue_tasks'], $plan->settings['target_days'], $plan->settings['max_backlog']);
    }

    /** @param array<string, mixed> $settings
     * @return array{target_days: int, preferred_time: string, max_backlog: int}
     */
    private function validatedSettings(array $settings): array
    {
        $validated = Validator::make($settings, [
            'target_days' => ['required', 'integer', 'min:1', 'max:7'],
            'preferred_time' => ['required', 'date_format:H:i'],
            'max_backlog' => ['required', 'integer', 'min:0', 'max:20'],
        ])->validate();

        return ['target_days' => (int) $validated['target_days'],
            'preferred_time' => (string) $validated['preferred_time'],
            'max_backlog' => (int) $validated['max_backlog']];
    }

    private function timezone(User $user): string
    {
        return UserPreference::query()->where('user_id', $user->id)->value('timezone') ?: 'UTC';
    }

    private function assertOwner(User $user): void
    {
        abort_unless($this->tenant->user()?->id === $user->id, 404);
    }
}
