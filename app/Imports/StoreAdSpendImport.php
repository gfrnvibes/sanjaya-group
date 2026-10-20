<?php

namespace App\Imports;

use App\Models\Store;
use App\Models\StoreAdSpend;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;

/**
 * Biaya iklan aktual per toko. Baris yang sama persis (toko, tanggal, jumlah) dengan data yang sudah ada dilewati.
 */
class StoreAdSpendImport extends RowImporter
{
    public function title(): string
    {
        return 'Biaya Iklan';
    }

    public function columns(): array
    {
        return [
            'TANGGAL' => ['spend_date', 'Ya', 'Tanggal biaya iklan.'],
            'NAMA TOKO' => ['store', 'Ya', 'Harus sama dengan nama toko di sistem (lihat sheet Daftar Toko).'],
            'BIAYA IKLAN' => ['amount', 'Ya', 'Jumlah rupiah, mis. 150000.'],
            'CATATAN' => ['note', '-', ''],
        ];
    }

    protected function requiredHeaders(): array
    {
        return ['spend_date', 'store', 'amount'];
    }

    protected function referenceSheets(): array
    {
        return ['Daftar Toko' => [[32], [['NAMA TOKO'], ...Store::query()->where('is_active', true)->orderBy('name')->pluck('name')->map(fn (string $name): array => [$name])->all()]]];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['store' => 'NAMA TOKO']);
        $date = $row->date('spend_date', 'TANGGAL', required: true);
        $amount = $row->number('amount', 'BIAYA IKLAN', min: 0, required: true);
        $row->maxLength(['note' => ['CATATAN', 255]]);
        $row->throwIfInvalid();

        $store = $this->store($row->text('store'));

        if ($this->alreadyExists("{$store->id}|{$date}|{$amount}", fn (): int => StoreAdSpend::query()
            ->where('store_id', $store->id)->whereDate('spend_date', $date)->where('amount', $amount)->count())) {
            $this->bump('skipped');

            return;
        }

        StoreAdSpend::create([
            'store_id' => $store->id,
            'spend_date' => $date,
            'amount' => $amount,
            'note' => $row->text('note') ?: null,
            'created_by' => auth()->id(),
        ]);
        $this->bump('created');
    }
}
