<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Defaults for new users
    |--------------------------------------------------------------------------
    |
    | IANA timezone assigned to new users until they choose their own.
    |
    */
    'default_timezone' => env('NUNEZ_DEFAULT_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | mode: "invite"   - only people holding a valid invitation link can register (default)
    |       "approval" - anyone can register, but cannot use the app until approved
    |                    with `php artisan nunez:user:approve {email}`
    |       "open"     - public self-registration (not recommended before launch)
    |
    */
    'registration' => [
        'mode' => env('NUNEZ_REGISTRATION_MODE', 'invite'),
        'invitation_ttl_days' => (int) env('NUNEZ_INVITATION_TTL_DAYS', 7),
    ],
];
