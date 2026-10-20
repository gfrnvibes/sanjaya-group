<?php

namespace App\Imports;

use App\Models\CashAccount;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;

/**
 * Rekening / kas: dicocokkan lewat nama; yang sudah ada diperbarui dari sel yang terisi.
 */
class CashAccountImport extends RowImporter
{
    public function title(): string
    {
        return 'Rekening / Kas';
    }

    public function columns(): array
    {
        return [
            'NAMA REKENING' => ['name', 'Ya', 'Nama unik, mis. "BCA Operasional" atau "Kas Toko".'],
            'BANK' => ['bank', '-', 'Nama bank (kosong untuk kas tunai).'],
            'PEMILIK REKENING' => ['holder', '-', 'Atas nama.'],
            'AKTIF' => ['is_active', '-', 'Ya / Tidak. Default Ya.'],
        ];
    }

    protected function requiredHeaders(): array
    {
        return ['name'];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['name' => 'NAMA REKENING']);
        $row->maxLength(['name' => ['NAMA REKENING', 80], 'bank' => ['BANK', 60], 'holder' => ['PEMILIK REKENING', 120]]);
        $active = $row->bool('is_active');
        $row->throwIfInvalid();

        $attributes = array_filter([
            'bank' => $row->text('bank') ?: null,
            'holder' => $row->text('holder') ?: null,
            'is_active' => $active,
        ], fn ($value): bool => $value !== null);

        $account = $this->findCi(CashAccount::query(), 'name', $row->text('name'));

        if ($account === null) {
            CashAccount::create(['name' => $row->text('name'), ...$attributes]);
            $this->bump('created');

            return;
        }

        $this->saveIfDirty($account->fill($attributes));
    }
}
