<?php

namespace App\Support;

/** Montants décimaux manipulés sous forme de chaînes ou de centimes entiers : jamais de flottant. */
final class Money
{
    /** « 1234567.89 » devient « 1 234 567,89 ». */
    public static function format(string|int|float|null $value): string
    {
        $value = (string) ($value ?? '0');
        $negative = str_starts_with($value, '-');

        [$integer, $decimals] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');

        $integer = $integer === '' ? '0' : $integer;
        $decimals = str_pad(substr($decimals, 0, 2), 2, '0');
        $grouped = strrev(implode(' ', str_split(strrev($integer), 3)));

        return ($negative ? '-' : '').$grouped.','.$decimals;
    }

    public static function toCents(string|int|float|null $value): int
    {
        $value = (string) ($value ?? '0');
        $negative = str_starts_with($value, '-');

        [$integer, $decimals] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');

        $cents = ((int) $integer) * 100 + (int) str_pad(substr($decimals, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    /** 123456 devient « 1234.56 ». */
    public static function fromCents(int $cents): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);

        return ($negative ? '-' : '').intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}