<?php

namespace App\Services\Notifications;

/**
 * Kenyan numbers are written locally far more often than in E.164, and a
 * provider will reject "0712 345 678" outright. Normalise before sending rather
 * than leaving every caller to remember.
 */
final class PhoneNumber
{
    public static function toE164(?string $number, string $countryCode = '254'): ?string
    {
        $digits = preg_replace('/[^0-9+]/', '', (string) $number) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '+')) {
            $digits = '+'.preg_replace('/\D/', '', substr($digits, 1));

            return self::valid($digits) ? $digits : null;
        }

        $digits = preg_replace('/\D/', '', $digits) ?? '';

        // 0712345678 → +254712345678
        if (str_starts_with($digits, '0')) {
            $digits = $countryCode.substr($digits, 1);
        } elseif (! str_starts_with($digits, $countryCode)) {
            $digits = $countryCode.$digits;
        }

        $candidate = '+'.$digits;

        return self::valid($candidate) ? $candidate : null;
    }

    private static function valid(string $candidate): bool
    {
        return (bool) preg_match('/^\+[1-9]\d{7,14}$/', $candidate);
    }
}
