<?php

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Services\GeneralLedger;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJournalEntries extends ListRecords
{
    protected static string $resource = JournalEntryResource::class;

    public function mount(): void
    {
        app(GeneralLedger::class)->ensureFresh();

        parent::mount();
    }

    public function getSubheading(): ?string
    {
        return 'Jurnal otomatis disusun dari setiap transaksi (tidak bisa diubah; ubah transaksinya). Jurnal manual untuk saldo awal kas, aset tetap, penyusutan, pinjaman, pembayaran pajak & koreksi.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Jurnal manual'),
        ];
    }
}
