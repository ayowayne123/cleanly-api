<?php

namespace App\Support;

class Phone
{
    // Nigerian numbers only, stored as +234XXXXXXXXXX. Returns null if it doesn't look right.
    public static function normalize(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '234')) {
            $national = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
        } else {
            $national = $digits;
        }

        // people sometimes type +234 0801...
        if (strlen($national) === 11 && str_starts_with($national, '0')) {
            $national = substr($national, 1);
        }

        return preg_match('/^\d{10}$/', $national) ? '+234'.$national : null;
    }
}