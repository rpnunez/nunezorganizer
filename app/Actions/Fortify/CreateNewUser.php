<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\RegistrationMode;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /** Registration attempts allowed per IP per minute. */
    public const int MAX_ATTEMPTS_PER_MINUTE = 5;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $mode = RegistrationMode::current();

        $this->ensureNotRateLimited();

        $rules = [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ];

        if ($mode === RegistrationMode::Invite) {
            $rules['invitation'] = ['required', 'string', 'max:255'];
        }

        Validator::make($input, $rules)->validate();

        return DB::transaction(function () use ($input, $mode): User {
            $invitation = null;

            if ($mode === RegistrationMode::Invite) {
                $invitation = Invitation::findUsable($input['invitation'], lockForUpdate: true);

                if ($invitation === null || ! $invitation->matchesEmail($input['email'])) {
                    throw ValidationException::withMessages([
                        'email' => __('This invitation is invalid, expired, already used, or for a different email address.'),
                    ]);
                }
            }

            $user = User::create([
                'name' => $input['name'],
                'email' => Invitation::normalizeEmail($input['email']),
                'password' => $input['password'],
            ]);

            if ($mode->approvesOnRegistration()) {
                $user->forceFill(['approved_at' => now()])->save();
            }

            $invitation?->markAccepted($user);

            return $user;
        });
    }

    private function ensureNotRateLimited(): void
    {
        $key = 'register|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS_PER_MINUTE)) {
            throw ValidationException::withMessages([
                'email' => __('Too many registration attempts. Please try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        RateLimiter::hit($key, 60);
    }
}
