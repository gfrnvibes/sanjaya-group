<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Carbon;

/**
 * Siklus tagihan reseller otomatis. Hari/tanggal mulai: mingguan 1 = Senin ... 7 = Minggu; bulanan 1-28.
 */
enum InvoiceCycle: string implements HasLabel
{
    case Weekly = 'mingguan';
    case Monthly = 'bulanan';

    public const WEEKDAYS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    public function getLabel(): string
    {
        return match ($this) {
            self::Weekly => 'Mingguan',
            self::Monthly => 'Bulanan',
        };
    }

    /**
     * Pilihan hari/tanggal mulai siklus.
     *
     * @return array<int, string>
     */
    public function dayOptions(): array
    {
        return match ($this) {
            self::Weekly => self::WEEKDAYS,
            self::Monthly => collect(range(1, 28))->mapWithKeys(fn (int $day): array => [$day => "Tanggal {$day}"])->all(),
        };
    }

    public function clampDay(?int $day): int
    {
        return match ($this) {
            self::Weekly => max(1, min(7, $day ?? 1)),
            self::Monthly => max(1, min(28, $day ?? 1)),
        };
    }

    /**
     * Periode siklus yang mencakup $date.
     *
     * @return array{0: Carbon, 1: Carbon} [mulai, selesai]
     */
    public function period(CarbonInterface|string $date, ?int $day): array
    {
        $date = Carbon::parse($date)->startOfDay();
        $day = $this->clampDay($day);

        if ($this === self::Weekly) {
            $start = $date->copy()->subDays(($date->dayOfWeekIso - $day + 7) % 7);

            return [$start, $start->copy()->addDays(6)];
        }

        $start = $date->day >= $day ? $date->copy()->day($day) : $date->copy()->subMonthNoOverflow()->day($day);

        return [$start, $start->copy()->addMonthNoOverflow()->subDay()];
    }

    /** Uraian siklus, mis. "Bulanan mulai tanggal 26" / "Mingguan mulai Senin". */
    public function describe(?int $day): string
    {
        $day = $this->clampDay($day);

        return $this->getLabel().' mulai '.($this === self::Weekly ? self::WEEKDAYS[$day] : "tanggal {$day}");
    }
}
