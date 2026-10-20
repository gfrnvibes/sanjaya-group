<?php

namespace App\Imports;

use App\Exceptions\BusinessRuleException;
use App\Models\CashAccount;
use App\Models\Tailor;
use App\Models\TailorPayment;
use App\Models\WorkOrder;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;

/**
 * Pembayaran upah penjahit. Penjahit & rekening yang belum ada dibuat otomatis.
 * Baris yang sama persis (penjahit, tanggal, jumlah) dengan data yang sudah ada dilewati.
 */
class TailorPaymentImport extends RowImporter
{
    public function title(): string
    {
        return 'Pembayaran Upah';
    }

    public function columns(): array
    {
        return [
            'TANGGAL BAYAR' => ['paid_at', 'Ya', 'Tanggal upah dibayar.'],
            'NAMA PENJAHIT' => ['tailor', 'Ya', 'Belum ada = dibuat otomatis.'],
            'JUMLAH' => ['amount', 'Ya', 'Jumlah rupiah (> 0).'],
            'NO SPK' => ['work_order', '-', 'Opsional: kode SPK yang dibayar (harus milik penjahit yang sama).'],
            'REKENING / KAS' => ['cash_account', '-', 'Sumber dana. Belum ada = dibuat otomatis.'],
            'CATATAN' => ['note', '-', ''],
        ];
    }

    protected function requiredHeaders(): array
    {
        return ['paid_at', 'tailor', 'amount'];
    }

    protected function referenceSheets(): array
    {
        return [
            'Daftar Penjahit' => [[32], [['NAMA PENJAHIT'], ...Tailor::query()->where('is_active', true)->orderBy('name')->pluck('name')->map(fn (string $name): array => [$name])->all()]],
            'Daftar Rekening' => [[30], [['REKENING / KAS'], ...CashAccount::query()->where('is_active', true)->orderBy('name')->pluck('name')->map(fn (string $name): array => [$name])->all()]],
        ];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['tailor' => 'NAMA PENJAHIT']);
        $date = $row->date('paid_at', 'TANGGAL BAYAR', required: true);
        $amount = $row->number('amount', 'JUMLAH', min: 0.01, required: true);
        $row->maxLength(['note' => ['CATATAN', 255]]);
        $row->throwIfInvalid();

        $tailor = $this->tailor($row->text('tailor'));
        $workOrder = null;

        if ($row->filled('work_order')) {
            $workOrder = $this->findCi(WorkOrder::query(), 'code', $row->text('work_order'))
                ?? throw new BusinessRuleException("SPK {$row->text('work_order')} tidak ditemukan");

            if ($workOrder->tailor_id !== null && $workOrder->tailor_id !== $tailor->id) {
                throw new BusinessRuleException("SPK {$workOrder->code} bukan milik penjahit {$tailor->name}");
            }
        }

        $account = $row->filled('cash_account') ? $this->cashAccount($row->text('cash_account')) : null;

        if ($this->alreadyExists("{$tailor->id}|{$date}|{$amount}", fn (): int => TailorPayment::query()
            ->where('tailor_id', $tailor->id)->whereDate('paid_at', $date)->where('amount', $amount)->count())) {
            $this->bump('skipped');

            return;
        }

        TailorPayment::create([
            'tailor_id' => $tailor->id,
            'work_order_id' => $workOrder?->id,
            'paid_at' => $date,
            'amount' => $amount,
            'cash_account_id' => $account?->id,
            'note' => $row->text('note') ?: null,
            'created_by' => auth()->id(),
        ]);
        $this->bump('created');
    }
}
