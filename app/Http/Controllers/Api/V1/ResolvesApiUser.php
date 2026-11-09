<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Middleware\EnforceIdempotency;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait ResolvesApiUser
{
    protected function apiUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    protected function userTimezone(User $user): string
    {
        return (string) (UserPreference::query()->where('user_id', $user->id)->value('timezone') ?? 'UTC');
    }

    /**
     * Domain idempotency key for a response: the client's Idempotency-Key when given
     * (so retries apply once), otherwise a fresh one. Namespaced to avoid colliding
     * with keys from other channels (telegram:, web:, correction:).
     */
    protected function responseIdempotencyKey(Request $request): string
    {
        $key = $request->header(EnforceIdempotency::HEADER);

        return 'api:'.(is_string($key) && $key !== '' ? Str::limit($key, 90, '') : Str::ulid());
    }
}
