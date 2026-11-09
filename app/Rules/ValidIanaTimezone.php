<?php

namespace App\Rules;

use Closure;
use DateTimeZone;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts only canonical IANA identifiers such as "America/New_York" or "UTC".
 * Abbreviations and legacy POSIX names ("EST", "EST5EDT") are rejected.
 */
class ValidIanaTimezone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! in_array($value, DateTimeZone::listIdentifiers(), true)) {
            $fail(__('The :attribute must be a valid IANA timezone, e.g. America/New_York.'));
        }
    }
}
