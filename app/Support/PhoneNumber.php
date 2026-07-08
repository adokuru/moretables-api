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
}
