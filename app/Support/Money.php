<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public const MAX_CENTS = 999_999_999_999;

    public static function toCents(string $amount): int
    {
        if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount) !== 1) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');
        $cents = (int) ($whole.str_pad($fraction, 2, '0'));

        if ($cents > self::MAX_CENTS) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }

        return $cents;
    }

    public static function fromCents(int $cents): string
    {
        if ($cents < 0 || $cents > self::MAX_CENTS) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }

        $whole = intdiv($cents, 100);
        $fraction = $cents % 100;

        return $whole.'.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }
}
