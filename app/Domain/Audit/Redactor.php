<?php

namespace App\Domain\Audit;

/**
 * Removes secrets from arbitrary structured data (audit metadata, log context).
 */
class Redactor
{
    public const string MASK = '[REDACTED]';

    /** Keys whose values are always masked (matched case-insensitively as substrings). */
    private const array SENSITIVE_KEY_FRAGMENTS = [
        'password', 'secret', 'token', 'authorization', 'cookie', 'api_key', 'apikey',
        'pairing_code', 'code_hash', 'recovery', 'two_factor',
    ];

    /** Value patterns masked wherever they appear in strings. */
    private const array SENSITIVE_VALUE_PATTERNS = [
        // Telegram bot token: <bot id>:<35+ chars>
        '/\b\d{6,12}:[A-Za-z0-9_-]{30,}\b/' => '[REDACTED_TELEGRAM_TOKEN]',
        // Bearer / Basic credentials
        '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i' => '$1 [REDACTED]',
        // Telegram API URLs embed the bot token
        '#api\.telegram\.org/bot[^/\s]+#i' => 'api.telegram.org/bot[REDACTED]',
    ];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $result[$key] = self::MASK;
            } elseif (is_array($value)) {
                $result[$key] = self::redact($value);
            } elseif (is_string($value)) {
                $result[$key] = self::redactString($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public static function redactString(string $value): string
    {
        foreach (self::SENSITIVE_VALUE_PATTERNS as $pattern => $replacement) {
            $value = (string) preg_replace($pattern, $replacement, $value);
        }

        return $value;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
