<?php

namespace App\Filament\Forms\Components;

use App\Support\IdNumber;
use Filament\Schemas\Components\StateCasts\Contracts\StateCast;

/**
 * State input angka: di form berupa teks "1.234.567,5", di server berupa angka.
 */
class IdNumberStateCast implements StateCast
{
    public function __construct(
        protected bool $isInteger = false,
        protected int $decimals = 4,
    ) {}

    /** Form => server. */
    public function get(mixed $state): mixed
    {
        return IdNumber::parse($state, $this->isInteger);
    }

    /** Server (angka / decimal dari database) => teks form. */
    public function set(mixed $state): mixed
    {
        $number = IdNumber::parse($state, $this->isInteger, preferMachine: true);

        return is_int($number) || is_float($number) ? IdNumber::format($number, $this->isInteger ? 0 : $this->decimals) : $number;
    }
}
