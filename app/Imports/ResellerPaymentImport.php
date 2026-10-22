<?php

namespace App\Imports;

use App\Enums\PaymentMethod;
use App\Models\CashAccount;
use App\Models\Reseller;
use App\Models\ResellerPayment;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;

/**
 * Pembayaran reseller ke konveksi. Rekening yang belum ada dibuat otomatis.
 * Baris yang sama persis (reseller, tanggal, jumlah, metode) dengan data yang sudah ada dilewati.
 */
class ResellerPaymentImport extends RowImporter
{
    public function title(): string
    {
        return 'Pembayaran Reseller';
    }

    public function permission(): string
    {
        return 'kelola keuangan';
    }

    public function columns(): array
    {
        return [
            'TANGGAL BAYAR' => ['paid_at', 'Ya', 'Tanggal pembayaran diterima.'],
            'NAMA RESELLER' => ['reseller', 'Ya', 'Harus sama dengan nama reseller di sistem (lihat sheet Daftar Reseller).'],
            'JUMLAH' => ['amount', 'Ya', 'Jumlah rupiah (> 0).'],
            'METODE' => ['method', '-', 'Transfer / Tunai / Potongan Biaya. Default Transfer.'],
            'REKENING / KAS' => ['cash_account', 'Kecuali Potongan Biaya', 'Rekening penerima. Belum ada = dibuat otomatis.'],
            'CATATAN' => ['note', '-', ''],
        ];
    }

    protected function requiredHeaders(): array
    {
        return ['paid_at', 'reseller', 'amount'];
    }

    protected function notes(): array
    {
        return ['Potongan Biaya: catat biayanya di Keuangan > Biaya Operasional dan tautkan ke pembayaran ini.'];
    }

    protected function referenceSheets(): array
    {
        return [
            'Daftar Reseller' => [[32], [['NAMA RESELLER'], ...Reseller::query()->where('is_active', true)->orderBy('name')->pluck('name')->map(fn (string $name): array => [$name])->all()]],
            'Daftar Rekening' => [[30], [['REKENING / KAS'], ...CashAccount::query()->where('is_active', true)->orderBy('name')->pluck('name')->map(fn (string $name): array => [$name])->all()]],
        ];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['reseller' => 'NAMA RESELLER']);
        $date = $row->date('paid_at', 'TANGGAL BAYAR', required: true);
        $amount = $row->number('amount', 'JUMLAH', min: 0.01, required: true);
        $method = $row->enum('method', PaymentMethod::class, 'METODE', ['tunai' => PaymentMethod::Cash, 'potongan' => PaymentMethod::CostOffset]) ?? PaymentMethod::Transfer;

        if ($method !== PaymentMethod::CostOffset) {
            $row->requireText(['cash_account' => 'REKENING / KAS']);
        }

        $row->maxLength(['note' => ['CATATAN', 255]]);
        $row->throwIfInvalid();

        $reseller = $this->reseller($row->text('reseller'));
        $account = $method === PaymentMethod::CostOffset ? null : $this->cashAccount($row->text('cash_account'));

        if ($this->alreadyExists("{$reseller->id}|{$date}|{$amount}|{$method->value}", fn (): int => ResellerPayment::query()
            ->where('reseller_id', $reseller->id)
            ->whereDate('paid_at', $date)
            ->where('amount', $amount)
            ->where('method', $method)
            ->count())) {
            $this->bump('skipped');

            return;
        }

        ResellerPayment::create([
            'reseller_id' => $reseller->id,
            'paid_at' => $date,
            'amount' => $amount,
            'method' => $method,
            'cash_account_id' => $account?->id,
            'note' => $row->text('note') ?: null,
            'created_by' => auth()->id(),
        ]);
        $this->bump('created');
    }
}
