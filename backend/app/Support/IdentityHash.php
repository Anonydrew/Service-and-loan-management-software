<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Keyed hash of an identity number, stored beside the encrypted value so
 * duplicate registrations can be detected without decrypting every row.
 * Normalises case and separators so "GHA-123456789-0" matches "gha1234567890".
 */
final class IdentityHash
{
    public static function make(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalised = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));

        return hash_hmac('sha256', $normalised, (string) config('app.key'));
    }

    /** Last four characters, for display. */
    public static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $clean = (string) preg_replace('/\s+/', '', $value);

        return str_repeat('•', max(0, strlen($clean) - 4)).substr($clean, -4);
    }
}
