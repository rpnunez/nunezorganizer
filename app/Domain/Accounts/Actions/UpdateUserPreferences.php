<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\TenantContext;
use App\Domain\Audit\Audit;
use App\Models\User;
use App\Models\UserPreference;
use App\Rules\ValidIanaTimezone;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validate and persist the authenticated user's preferences (UI arrives in #13).
 * Only the current tenant's own preference row can be changed.
 */
class UpdateUserPreferences
{
    private const string TIME = 'date_format:H:i';

    public const array WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const array REVIEW_KINDS = ['morning', 'afternoon', 'evening'];

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): UserPreference
    {
        if ($this->tenant->user()?->id !== $user->id) {
            throw ValidationException::withMessages(['user' => __('You may only update your own preferences.')]);
        }

        $validated = Validator::make($input, $this->rules())->validate();

        // Scoped to the current tenant by BelongsToAccount; another user's row is unreachable.
        $preference = UserPreference::query()->where('user_id', $user->id)->firstOrFail();

        $preference->fill($validated);
        $changed = array_keys($preference->getDirty());
        $preference->save();

        if ($changed !== []) {
            Audit::record('preferences.updated', $preference, ['fields' => $changed], actor: $user);
        }

        return $preference;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'timezone' => ['sometimes', 'required', 'string', new ValidIanaTimezone],
            'grace_minutes' => ['sometimes', 'required', 'integer', 'min:0', 'max:240'],
            'unanswered_after_minutes' => ['sometimes', 'required', 'integer', 'min:15', 'max:10080'],
            'preferred_activity_start' => ['sometimes', 'nullable', self::TIME],

            'quiet_hours' => ['sometimes', 'nullable', 'array:start,end'],
            'quiet_hours.start' => ['required_with:quiet_hours', self::TIME],
            'quiet_hours.end' => ['required_with:quiet_hours', self::TIME, 'different:quiet_hours.start'],

            'review_times' => ['sometimes', 'nullable', 'array:'.implode(',', self::REVIEW_KINDS)],
            'notification_preferences' => ['sometimes', 'nullable', 'array'],

            'work_windows' => ['sometimes', 'nullable', 'array:'.implode(',', self::WEEKDAYS)],
        ];

        foreach (self::REVIEW_KINDS as $kind) {
            $rules["review_times.{$kind}"] = ['nullable', self::TIME];
        }

        foreach (self::WEEKDAYS as $day) {
            $rules["work_windows.{$day}"] = ['array', 'max:4'];
            $rules["work_windows.{$day}.*"] = ['array:start,end'];
            $rules["work_windows.{$day}.*.start"] = ['required', self::TIME];
            $rules["work_windows.{$day}.*.end"] = ['required', self::TIME, "after:work_windows.{$day}.*.start"];
        }

        return $rules;
    }
}
