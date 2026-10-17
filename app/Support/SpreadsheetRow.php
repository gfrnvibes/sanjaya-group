<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use BackedEnum;
use Illuminate\Support\Str;

/**
 * Pembaca nilai satu baris impor: parse + kumpulkan error format, lalu lempar sekaligus.
 */
class SpreadsheetRow
{
    /** @var list<string> */
    private array $errors = [];

    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private array $values) {}

    public function text(string $key): string
    {
        return Spreadsheet::text($this->values[$key] ?? null);
    }

    public function filled(string $key): bool
    {
        return $this->text($key) !== '';
    }

    /**
     * @param  array<string, string>  $labels  kunci => label kolom
     */
    public function requireText(array $labels): void
    {
        foreach ($labels as $key => $label) {
            if (! $this->filled($key)) {
                $this->errors[] = "{$label} kosong";
            }
        }
    }

    /**
     * @param  array<string, array{0: string, 1: int}>  $limits  kunci => [label, maks]
     */
    public function maxLength(array $limits): void
    {
        foreach ($limits as $key => [$label, $max]) {
            if (mb_strlen($this->text($key)) > $max) {
                $this->errors[] = "{$label} maksimal {$max} karakter";
            }
        }
    }

    public function number(string $key, string $label, ?float $min = null, bool $required = false, bool $integer = false): ?float
    {
        if (! $this->filled($key)) {
            if ($required) {
                $this->errors[] = "{$label} kosong";
            }

            return null;
        }

        $number = Spreadsheet::parseNumber($this->values[$key]);

        if ($number === null || ($integer && floor($number) !== $number)) {
            $this->errors[] = "{$label} harus ".($integer ? 'bilangan bulat' : 'angka');

            return null;
        }

        if ($min !== null && $number < $min) {
            $this->errors[] = "{$label} minimal ".rtrim(rtrim(number_format($min, 4, '.', ''), '0'), '.');

            return null;
        }

        return $number;
    }

    public function percent(string $key, string $label): ?float
    {
        if (! $this->filled($key)) {
            return null;
        }

        $value = Spreadsheet::parsePercent($this->values[$key]);

        if ($value === null) {
            $this->errors[] = "{$label} harus angka persen";
        }

        return $value;
    }

    public function date(string $key, string $label, bool $required = false): ?string
    {
        if (! $this->filled($key)) {
            if ($required) {
                $this->errors[] = "{$label} kosong";
            }

            return null;
        }

        $date = Spreadsheet::parseDate($this->values[$key]);

        if ($date === null) {
            $this->errors[] = "{$label} formatnya tidak dikenali";
        }

        return $date;
    }

    public function bool(string $key, string $label = 'AKTIF'): ?bool
    {
        if (! $this->filled($key)) {
            return null;
        }

        $value = Spreadsheet::parseBool($this->values[$key]);

        if ($value === null) {
            $this->errors[] = "{$label} harus Ya / Tidak";
        }

        return $value;
    }

    /**
     * Enum dari nilai ("beli_jadi"), label ("Beli Jadi"), atau alias tambahan.
     *
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @param  array<string, TEnum>  $aliases
     * @return TEnum|null
     */
    public function enum(string $key, string $enum, string $label, array $aliases = [], bool $required = false): ?BackedEnum
    {
        if (! $this->filled($key)) {
            if ($required) {
                $this->errors[] = "{$label} kosong";
            }

            return null;
        }

        $value = Str::lower($this->text($key));
        $case = $enum::tryFrom($value)
            ?? $enum::tryFrom(str_replace(' ', '_', $value))
            ?? collect($enum::cases())->first(fn (BackedEnum $case): bool => method_exists($case, 'getLabel') && Str::lower($case->getLabel()) === $value)
            ?? ($aliases[$value] ?? null);

        if ($case === null) {
            $options = collect($enum::cases())
                ->map(fn (BackedEnum $case): string => method_exists($case, 'getLabel') ? $case->getLabel() : (string) $case->value)
                ->implode(' / ');
            $this->errors[] = "{$label} harus {$options}";
        }

        return $case;
    }

    public function throwIfInvalid(): void
    {
        if ($this->errors !== []) {
            $message = implode('; ', array_unique($this->errors)).'.';
            $this->errors = [];

            throw new BusinessRuleException($message);
        }
    }
}
