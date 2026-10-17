<?php

namespace App\Filament\Resources\VariantCosts\Pages;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\VariantCosts\VariantCostResource;
use App\Models\ProductVariant;
use App\Services\CostingService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageVariantCosts extends ManageRecords
{
    protected static string $resource = VariantCostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshAll')
                ->label('Hitung Ulang Semua HPP dari BOM')
                ->icon(Heroicon::OutlinedCalculator)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Hitung HPP setiap varian aktif berjenis produksi dari resep x harga beli bahan terakhir, berlaku hari ini.')
                ->action(function (CostingService $costing): void {
                    $variants = ProductVariant::query()
                        ->where('is_active', true)
                        ->whereHas('model', fn ($query) => $query->where('kind', 'produksi'))
                        ->get();
                    $failed = 0;

                    foreach ($variants as $variant) {
                        try {
                            $costing->refreshVariantCost($variant);
                        } catch (BusinessRuleException) {
                            $failed++;
                        }
                    }

                    Notification::make()
                        ->title(($variants->count() - $failed).' HPP diperbarui')
                        ->body($failed > 0 ? "{$failed} varian dilewati (resep/bahan belum lengkap)." : null)
                        ->color($failed > 0 ? 'warning' : 'success')
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
