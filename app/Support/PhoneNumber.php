<?php

namespace App\Support;

class PhoneNumber
{
    public static function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    public static function forWhatsApp(string $phone): string
    {
        $digits = self::digits($phone);

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '234'.substr($digits, 1);
        }

        return $digits;
    }

    /**
     * Mask a number for logging. Keeps the leading three digits and the last
     * four, and preserves the overall length, so two numbers that failed to
     * match can be compared by shape without writing full numbers to the logs.
     */
    public static function mask(string $phone): string
    {
        $digits = self::digits($phone);

        if ($digits === '') {
            return '';
        }

        if (strlen($digits) <= 7) {
            return str_repeat('*', strlen($digits));
        }

        return substr($digits, 0, 3).str_repeat('*', strlen($digits) - 7).substr($digits, -4);
    }
}
