<?php

namespace App\Observers;

use App\Models\OwnerTierHistory;
use App\Models\PriceTier;
use App\Services\TierRepricer;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Tier langsung berlaku: setiap riwayat tier pemilik / % tier berubah, harga penjualan yang belum
 * masuk tagihan terbit disinkronkan ulang (lihat TierRepricer).
 */
class TierObserver
{
    public function saved(OwnerTierHistory|PriceTier $model): void
    {
        if ($model instanceof PriceTier) {
            if ($model->wasChanged('adjustment_pct')) {
                $this->reprice(
                    $model->ownerTierHistories()->distinct()->pluck('owner_id')->all(),
                    $model->ownerTierHistories()->min('effective_from'),
                );
            }

            return;
        }

        if ($model->wasRecentlyCreated || $model->wasChanged(['owner_id', 'price_tier_id', 'effective_from'])) {
            $dates = array_filter([$model->effective_from, $model->getOriginal('effective_from')]);
            $owners = array_unique(array_filter([$model->owner_id, $model->getOriginal('owner_id')]));

            $this->reprice(array_values($owners), collect($dates)->min());
        }
    }

    public function deleted(OwnerTierHistory|PriceTier $model): void
    {
        if ($model instanceof OwnerTierHistory) {
            $this->reprice([$model->owner_id], $model->effective_from);
        }
    }

    /**
     * @param  list<int>  $ownerIds
     */
    private function reprice(array $ownerIds, mixed $from): void
    {
        $result = app(TierRepricer::class)->reprice($ownerIds, $from ? Carbon::parse($from)->toDateString() : null);

        if (app()->runningInConsole() || ($result['updated'] === 0 && $result['locked'] === 0)) {
            return;
        }

        Notification::make()
            ->title("Tier disinkronkan: harga {$result['updated']} baris penjualan disesuaikan")
            ->body($result['locked'] > 0 ? "{$result['locked']} baris di tagihan yang sudah terbit tidak diubah (batalkan tagihannya bila perlu ditagih ulang)." : null)
            ->success()
            ->send();
    }
}
