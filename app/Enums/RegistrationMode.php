<?php

namespace App\Enums;

enum RegistrationMode: string
{
    case Invite = 'invite';
    case Approval = 'approval';
    case Open = 'open';

    /**
     * The configured mode. Unknown values fall back to the safest mode (invite).
     */
    public static function current(): self
    {
        $configured = config('nunezorganizer.registration.mode');

        return self::tryFrom(is_string($configured) ? strtolower($configured) : '') ?? self::Invite;
    }

    /**
     * Whether people can reach the registration form without an invitation.
     */
    public function allowsSelfSignup(): bool
    {
        return $this !== self::Invite;
    }

    /**
     * Whether newly registered users may use the app immediately.
     */
    public function approvesOnRegistration(): bool
    {
        return $this !== self::Approval;
    }
}
