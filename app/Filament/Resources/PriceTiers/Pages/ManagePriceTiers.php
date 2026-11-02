<?php

namespace App\Filament\Resources\PriceTiers\Pages;

use App\Filament\Actions\PrintPriceListAction;
use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\PriceTiers\PriceTierResource;
use App\Imports\PriceTierImport;
use App\Services\TierRepricer;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManagePriceTiers extends ManageRecords
{
    protected static string $resource = PriceTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PrintPriceListAction::make(),
            // Sinkron otomatis berjalan saat tier / riwayat tier diubah; tombol ini untuk menyamakan data lama sekaligus.
            Action::make('syncTiers')
                ->label('Sinkronkan semua')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Harga semua penjualan yang belum masuk tagihan terbit disesuaikan dengan tier pemilik pada tanggal pesanannya.')
                ->action(function (TierRepricer $repricer): void {
                    $result = $repricer->reprice();

                    Notification::make()
                        ->title("{$result['updated']} baris penjualan disesuaikan")
                        ->body($result['locked'] > 0 ? "{$result['locked']} baris di tagihan terbit tidak diubah." : null)
                        ->success()
                        ->send();
                }),
            ...ResourceIo::make(PriceTierImport::class),
            CreateAction::make(),
        ];
    }
}
