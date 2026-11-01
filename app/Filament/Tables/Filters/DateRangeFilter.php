<?php

namespace App\Filament\Tables\Filters;

use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Filter periode dengan pilihan cepat (kemarin, 7 hari, 1 bulan, 3 bulan, ...) yang otomatis mengisi tanggal,
 * atau isi tanggal sendiri. Indikator filter ikut tercetak di halaman Cetak / PDF.
 */
class DateRangeFilter
{
    public const CUSTOM = 'custom';

    public const PRESETS = [
        'today' => 'Hari ini',
        'yesterday' => 'Kemarin',
        'last_7_days' => '7 hari terakhir',
        'last_month' => '1 bulan terakhir',
        'last_3_months' => '3 bulan terakhir',
        'this_month' => 'Bulan ini',
        'previous_month' => 'Bulan lalu',
        'this_year' => 'Tahun ini',
        self::CUSTOM => 'Pilih tanggal',
    ];

    /**
     * @param  Closure(Builder, ?string $from, ?string $until, array<string, mixed> $data): Builder  $apply
     * @param  array<int, mixed>  $extraFields  isian tambahan di atas pilihan periode (mis. "berdasarkan tanggal")
     */
    public static function make(string $name, string $label, Closure $apply, array $extraFields = []): Filter
    {
        return Filter::make($name)
            ->label($label)
            ->schema([
                ...$extraFields,
                Select::make('preset')
                    ->label($label)
                    ->options(self::PRESETS)
                    ->placeholder('Semua tanggal')
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if ($state === null) {
                            $set('from', null);
                            $set('until', null);

                            return;
                        }

                        if ($state !== self::CUSTOM) {
                            [$from, $until] = self::range($state);
                            $set('from', $from);
                            $set('until', $until);
                        }
                    }),
                DatePicker::make('from')
                    ->label('Dari')
                    ->live()
                    ->afterStateUpdated(fn (Set $set, Get $get) => self::markCustom($set, $get)),
                DatePicker::make('until')
                    ->label('Sampai')
                    ->live()
                    ->afterStateUpdated(fn (Set $set, Get $get) => self::markCustom($set, $get)),
            ])
            ->columns(['default' => 1, 'sm' => 2])
            ->query(function (Builder $query, array $data) use ($apply): Builder {
                [$from, $until] = self::resolve($data);

                return ($from || $until) ? $apply($query, $from, $until, $data) : $query;
            })
            ->indicateUsing(function (array $data) use ($label): ?string {
                [$from, $until] = self::resolve($data);

                if (! $from && ! $until) {
                    return null;
                }

                $format = fn (?string $date): string => $date ? Carbon::parse($date)->format('d/m/Y') : '…';
                $preset = $data['preset'] ?? null;
                $name = $preset && $preset !== self::CUSTOM ? self::PRESETS[$preset].': ' : '';

                return "{$label}: {$name}{$format($from)} – {$format($until)}";
            });
    }

    /**
     * Rentang tanggal [dari, sampai] untuk pilihan cepat, dihitung saat filter dipakai (bukan saat disimpan),
     * sehingga "7 hari terakhir" tetap benar keesokan harinya.
     *
     * @return array{0: string, 1: string}
     */
    public static function range(string $preset): array
    {
        $today = today();

        [$from, $until] = match ($preset) {
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'last_7_days' => [$today->copy()->subDays(6), $today],
            'last_month' => [$today->copy()->subMonthNoOverflow()->addDay(), $today],
            'last_3_months' => [$today->copy()->subMonthsNoOverflow(3)->addDay(), $today],
            'this_month' => [$today->copy()->startOfMonth(), $today],
            'previous_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [$today->copy()->startOfYear(), $today],
            default => [$today, $today],
        };

        return [$from->toDateString(), $until->toDateString()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: ?string}
     */
    public static function resolve(array $data): array
    {
        $preset = $data['preset'] ?? null;

        if ($preset && $preset !== self::CUSTOM && isset(self::PRESETS[$preset])) {
            return self::range($preset);
        }

        return [
            filled($data['from'] ?? null) ? Carbon::parse($data['from'])->toDateString() : null,
            filled($data['until'] ?? null) ? Carbon::parse($data['until'])->toDateString() : null,
        ];
    }

    private static function markCustom(Set $set, Get $get): void
    {
        $preset = $get('preset');

        // tanggal diubah manual dan tidak lagi sama dengan pilihan cepat → "Pilih tanggal"
        if ($preset !== self::CUSTOM && ($preset === null || self::range($preset) !== [(string) $get('from'), (string) $get('until')])) {
            $set('preset', self::CUSTOM);
        }
    }
}
