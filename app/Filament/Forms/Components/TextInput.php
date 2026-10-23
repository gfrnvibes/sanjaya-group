<?php

namespace App\Filament\Forms\Components;

use App\Support\IdNumber;
use Filament\Forms\Components\TextInput as BaseTextInput;
use Filament\Schemas\Components\StateCasts\NumberStateCast;
use Filament\Support\RawJs;

/**
 * TextInput aplikasi (dipasang menggantikan TextInput Filament di AppServiceProvider):
 * input ->numeric() / ->integer() otomatis ditulis dengan pemisah ribuan titik dan desimal koma ("1.250.000", "12,5").
 * Pengecualian (mis. tahun): ->plainNumber().
 */
class TextInput extends BaseTextInput
{
    protected bool $isPlainNumber = false;

    public function plainNumber(bool $condition = true): static
    {
        $this->isPlainNumber = $condition;

        return $this;
    }

    public function isThousandsFormatted(): bool
    {
        return $this->isNumeric() && ! $this->isPlainNumber && $this->evaluate($this->mask) === null;
    }

    public function getMask(): string|RawJs|null
    {
        if (! $this->isThousandsFormatted()) {
            return parent::getMask();
        }

        return RawJs::make('$money($input, \',\', \'.\', '.($this->isInteger() ? 0 : 4).')');
    }

    public function getDefaultStateCasts(): array
    {
        $casts = parent::getDefaultStateCasts();

        if (! $this->isThousandsFormatted()) {
            return $casts;
        }

        return [
            ...array_filter($casts, fn ($cast): bool => ! $cast instanceof NumberStateCast),
            app(IdNumberStateCast::class, ['isInteger' => $this->isInteger()]),
        ];
    }

    public function mutateStateForValidation(mixed $state): mixed
    {
        if ($this->isThousandsFormatted()) {
            $state = IdNumber::parse($state, $this->isInteger());
        }

        return parent::mutateStateForValidation($state);
    }

    public function mutatesStateForValidation(): bool
    {
        return parent::mutatesStateForValidation() || $this->isThousandsFormatted();
    }
}
