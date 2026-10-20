<?php

namespace App\Filament\Widgets\SalesDashboard;

use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;

/**
 * Kolom compact seragam untuk tabel Dashboard Produk (format angka Indonesia).
 */
class Columns
{
    public static function rank(): TextColumn
    {
        return TextColumn::make('rank')->label('No')->size(TextSize::ExtraSmall)->color('gray')->width('3rem');
    }

    public static function text(string $name, string $label, bool $sortable = true): TextColumn
    {
        return TextColumn::make($name)->label($label)->size(TextSize::Small)->sortable($sortable)->placeholder('-');
    }

    public static function number(string $name, string $label, int $decimals = 0): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->size(TextSize::Small)
            ->numeric(decimalPlaces: $decimals, locale: 'id')
            ->alignEnd()
            ->sortable()
            ->placeholder('-');
    }

    public static function money(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->size(TextSize::Small)
            ->money('IDR', locale: 'id', decimalPlaces: 0)
            ->alignEnd()
            ->sortable();
    }

    /**
     * Stok: merah bila minus.
     */
    public static function stock(string $name = 'stock', string $label = 'Stok'): TextColumn
    {
        return self::number($name, $label)->color(fn ($state): ?string => $state < 0 ? 'danger' : null);
    }

    /**
     * Hari stok dibulatkan ke bawah (seperti sheet).
     */
    public static function daysOfStock(): TextColumn
    {
        return TextColumn::make('days_of_stock')
            ->label('Hari Stok')
            ->size(TextSize::Small)
            ->formatStateUsing(fn ($state): string => number_format(floor((float) $state), 0, ',', '.'))
            ->alignEnd()
            ->sortable()
            ->placeholder('-');
    }

    public static function percent(string $name, string $label, float $warnFrom = 0.1): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->size(TextSize::Small)
            ->formatStateUsing(fn ($state): string => number_format((float) $state * 100, 1, ',', '.').'%')
            ->color(fn ($state): ?string => $state !== null && $state >= $warnFrom ? 'danger' : null)
            ->weight('bold')
            ->alignEnd()
            ->sortable()
            ->placeholder('-');
    }

    public static function priority(): TextColumn
    {
        return TextColumn::make('priority')
            ->label('Prioritas')
            ->badge()
            ->size(TextSize::ExtraSmall)
            ->color(fn (string $state): string => match ($state) {
                '1 HABIS' => 'danger',
                '2 SEGERA' => 'warning',
                '3 PERLU' => 'info',
                'AMAN' => 'success',
                default => 'gray',
            })
            ->sortable();
    }
}
