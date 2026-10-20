<?php

namespace App\Imports;

use App\Models\CashAccount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;

/**
 * Biaya operasional konveksi. Kategori & rekening yang belum ada dibuat otomatis.
 * Baris yang sama persis (tanggal, kategori, jumlah, toko, reseller) dengan data yang sudah ada dilewati.
 */
class ExpenseImport extends RowImporter
{
    public function title(): string
    {
        return 'Biaya Operasional';
    }

    public function columns(): array
    {
        return [
            'TANGGAL' => ['expense_date', 'Ya', 'Tanggal biaya.'],
            'KATEGORI BIAYA' => ['category', 'Ya', 'Kode/nama kategori (lihat sheet Daftar Kategori). Belum ada = dibuat otomatis.'],
            'JUMLAH' => ['amount', 'Ya', 'Jumlah rupiah (> 0).'],
            'NAMA TOKO' => ['store', '-', 'Opsional: biaya untuk toko tertentu.'],
            'NAMA RESELLER' => ['reseller', '-', 'Opsional: biaya untuk reseller tertentu.'],
            'REKENING / KAS' => ['cash_account', '-', 'Sumber dana. Belum ada = dibuat otomatis.'],
            'CATATAN' => ['note', '-', ''],
        ];
    }

    protected function requiredHeaders(): array
    {
        return ['expense_date', 'category', 'amount'];
    }

    protected function referenceSheets(): array
    {
        return [
            'Daftar Kategori' => [[20, 30], [['KODE KATEGORI', 'NAMA KATEGORI'], ...ExpenseCategory::query()->orderBy('code')->get()->map(fn (ExpenseCategory $c): array => [$c->code, $c->name])->all()]],
            'Daftar Rekening' => [[30], [['REKENING / KAS'], ...CashAccount::query()->where('is_active', true)->orderBy('name')->pluck('name')->map(fn (string $name): array => [$name])->all()]],
        ];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['category' => 'KATEGORI BIAYA']);
        $date = $row->date('expense_date', 'TANGGAL', required: true);
        $amount = $row->number('amount', 'JUMLAH', min: 0.01, required: true);
        $row->maxLength(['note' => ['CATATAN', 255]]);
        $row->throwIfInvalid();

        $category = $this->expenseCategory($row->text('category'));
        $store = $row->filled('store') ? $this->store($row->text('store')) : null;
        $reseller = $row->filled('reseller') ? $this->reseller($row->text('reseller')) : ($store?->reseller);
        $account = $row->filled('cash_account') ? $this->cashAccount($row->text('cash_account')) : null;

        $signature = implode('|', [$date, $category->id, $amount, $store?->id, $reseller?->id]);

        if ($this->alreadyExists($signature, fn (): int => Expense::query()
            ->whereDate('expense_date', $date)
            ->where('category_id', $category->id)
            ->where('amount', $amount)
            ->where('store_id', $store?->id)
            ->where('reseller_id', $reseller?->id)
            ->count())) {
            $this->bump('skipped');

            return;
        }

        Expense::create([
            'expense_date' => $date,
            'category_id' => $category->id,
            'amount' => $amount,
            'store_id' => $store?->id,
            'reseller_id' => $reseller?->id,
            'cash_account_id' => $account?->id,
            'note' => $row->text('note') ?: null,
            'created_by' => auth()->id(),
        ]);
        $this->bump('created');
    }
}
