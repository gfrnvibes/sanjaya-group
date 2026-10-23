<?php

namespace App\Support;

/**
 * Angka format Indonesia di input form: titik = pemisah ribuan, koma = desimal ("1.234.567,5").
 */
class IdNumber
{
    /** "1.500", "12.345.678", "1.234,5", "0,25": jelas format Indonesia (bukan angka mesin "1.5"). */
    private const FORMATTED = '/^-?\d{1,3}(\.\d{3})+(,\d*)?$|^-?\d+,\d*$/';

    /**
     * Teks input => angka. $preferMachine: teks tanpa koma dibaca sebagai angka mesin ("1.500" dari decimal:3 = 1,5).
     * Teks yang bukan angka dikembalikan apa adanya (biar ditolak validasi numeric).
     */
    public static function parse(mixed $value, bool $integer = false, bool $preferMachine = false): mixed
    {
        if (is_int($value) || is_float($value)) {
            return $integer ? (int) round($value) : $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $text = str_replace([' ', "\u{00A0}"], '', trim($value));

        if ($text === '' || $text === '-') {
            return null;
        }

        $machine = $preferMachine
            ? ! str_contains($text, ',') && is_numeric($text)
            : ! preg_match(self::FORMATTED, $text) && is_numeric($text);

        if (! $machine) {
            $text = str_replace(['.', ','], ['', '.'], $text);
        }

        if (! is_numeric($text)) {
            return $value;
        }

        return $integer ? (int) round((float) $text) : (float) $text;
    }

    /**
     * Angka => teks format Indonesia, desimal maksimal $decimals (nol di belakang dibuang).
     */
    public static function format(int|float $number, int $decimals = 4): string
    {
        $text = number_format($number, $decimals, ',', '.');

        return $decimals > 0 ? rtrim(rtrim($text, '0'), ',') : $text;
    }
}
