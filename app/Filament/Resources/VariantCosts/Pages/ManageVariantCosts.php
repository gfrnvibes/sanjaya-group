<?php

namespace App\Filament\Resources\VariantCosts\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\VariantCosts\VariantCostResource;
use App\Filament\Support\PriceSyncNotification;
use App\Models\ProductVariant;
use App\Services\PricingService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageVariantCosts extends ManageRecords
{
    protected static string $resource = VariantCostResource::class;

    public function getSubheading(): ?string
    {
        return 'HPP dihitung dari resep x harga beli bahan terakhir; harga reseller ikut diperbarui otomatis.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(),
            Action::make('refreshAll')
                ->label('Hitung Ulang Semua HPP & Harga')
                ->icon(Heroicon::OutlinedCalculator)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Hitung HPP setiap varian aktif berjenis produksi dari resep x harga beli bahan terakhir, lalu buat harga reseller otomatis (HPP x (1 + margin)) berlaku hari ini.')
                ->action(function (PricingService $pricing): void {
                    $variants = ProductVariant::query()
                        ->where('is_active', true)
                        ->whereHas('model', fn ($query) => $query->where('kind', 'produksi'))
                        ->get();

                    PriceSyncNotification::send($pricing->syncVariants($variants, today()));
                }),
        ];
    }
}
