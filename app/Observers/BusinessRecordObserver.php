<?php

namespace App\Observers;

use App\Enums\InvoiceCycle;
use App\Enums\StockOpnameStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\MaterialOpname;
use App\Models\MaterialOpnameItem;
use App\Models\Reseller;
use App\Models\Setting;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\Store;
use App\Models\WorkOrder;
use App\Services\GeneralLedger;
use App\Services\SalesDashboardService;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class BusinessRecordObserver
{
    /** Tanggal transaksi yang dikunci setelah tutup buku (per model). */
    private const LEDGER_DATES = [
        'SalesOrder' => ['order_date'],
        'SalesReturn' => ['returned_at'],
        'ResellerPayment' => ['paid_at'],
        'Expense' => ['expense_date'],
        'EquityTransaction' => ['transacted_at'],
        'MaterialPurchase' => ['purchase_date'],
        'TailorPayment' => ['paid_at'],
        'GoodsReceipt' => ['received_at'],
        'SupplierPayment' => ['paid_at'],
        'StockAdjustment' => ['adjusted_at'],
        'StockOpname' => ['counted_at'],
        'MaterialOpname' => ['counted_at'],
        'WorkOrder' => ['cut_date', 'finished_date'],
        'StoreAdSpend' => ['spend_date'],
    ];

    /**
     * Transaksi di bulan yang sudah ditutup buku tidak boleh ditambah, diubah atau dihapus.
     * SPK: hanya tanggal yang berubah yang dicek (SPK lama boleh diselesaikan di bulan berjalan).
     */
    private function ensureLedgerOpen(Model $model, bool $deleting = false): void
    {
        $fields = self::LEDGER_DATES[class_basename($model)] ?? [];

        if ($fields === [] || ! DB::table('ledger_closings')->exists()) {
            return;
        }

        $ledger = app(GeneralLedger::class);

        foreach ($fields as $field) {
            if ($model instanceof WorkOrder && $model->exists && ! $deleting && ! $model->isDirty($field)) {
                continue;
            }

            $dates = $deleting || ! $model->exists ? [$model->getAttribute($field)] : [$model->getAttribute($field), $model->getRawOriginal($field)];

            foreach (array_filter($dates) as $date) {
                $ledger->ensureOpen($date);
            }
        }
    }

    private function ensureOpnameEditable(Model $model): void
    {
        [$class, $id] = match (true) {
            $model instanceof StockOpname => [StockOpname::class, $model->getKey()],
            $model instanceof StockOpnameItem => [StockOpname::class, $model->opname_id],
            $model instanceof MaterialOpname => [MaterialOpname::class, $model->getKey()],
            $model instanceof MaterialOpnameItem => [MaterialOpname::class, $model->opname_id],
            default => [null, null],
        };
        if ($id !== null) {
            $parent = $class::query()->lockForUpdate()->find($id);
            if ($parent?->status === StockOpnameStatus::Posted) {
                throw new BusinessRuleException('Opname sudah diposting dan tidak boleh diubah/dihapus.');
            }
        }
    }

    public function deleting(Model $model): void
    {
        $this->ensureOpnameEditable($model);
        $this->ensureLedgerOpen($model, deleting: true);
    }

    public function saving(Model $model): void
    {
        $this->ensureOpnameEditable($model);
        $this->ensureLedgerOpen($model);
        if ($model instanceof Setting && $model->key === 'report_emails') {
            foreach (preg_split('/[\s,;]+/', (string) $model->value, -1, PREG_SPLIT_NO_EMPTY) as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new BusinessRuleException('Email penerima laporan tidak valid.');
                }
            }
        }
        if ($model instanceof Setting && $model->key === 'supplier_billing_start' && strtotime((string) $model->value) === false) {
            throw new BusinessRuleException('Tanggal mulai tagihan supplier harus tanggal (YYYY-MM-DD).');
        }
        if ($model instanceof Setting && $model->key === 'invoice_cycle' && InvoiceCycle::tryFrom((string) $model->value) === null) {
            throw new BusinessRuleException('Siklus tagihan harus "mingguan" atau "bulanan".');
        }
        if ($model instanceof Setting && ! in_array($model->key, ['report_emails', 'supplier_billing_start', 'invoice_cycle'], true)) {
            $value = $model->value;
            if (! is_numeric($value) || (float) $value < 0) {
                throw new BusinessRuleException('Nilai pengaturan harus angka yang tidak negatif.');
            }
            if (in_array($model->key, ['price_rounding', 'tier_price_rounding', 'analysis_days', 'production_batch', 'dead_stock_days', 'default_warehouse_id', 'default_material_warehouse_id'], true)
                && ((float) $value < 1 || (float) (int) $value !== (float) $value)) {
                throw new BusinessRuleException('Nilai pengaturan ini harus bilangan bulat minimal 1.');
            }
            $model->value = (float) $value;
        }
    }

    public function creating(Model $model): void
    {
        $field = match (class_basename($model)) {
            'WorkOrder' => 'code', 'GoodsReceipt' => 'receipt_no', 'SalesOrder' => 'order_no', default => null,
        };
        if ($field && blank($model->{$field})) {
            $prefix = match (class_basename($model)) {
                'WorkOrder' => 'SPK-', 'GoodsReceipt' => 'BM-', default => 'ORD-',
            };
            $model->{$field} = DocumentNumber::next($prefix.now()->format('ymd').'-', $model->getTable(), $field);
        }
    }

    public function updating(Model $model): void
    {
        if ($model instanceof Store && $model->isDirty(['reseller_id', 'channel_id']) && $model->salesOrders()->exists()) {
            throw new BusinessRuleException('Reseller/channel toko yang sudah memiliki penjualan tidak boleh diganti. Buat toko baru untuk perpindahan.');
        }

        if ($model instanceof Reseller && $model->isDirty('owner_id') && ($model->stores()->exists() || $model->invoices()->exists() || $model->payments()->exists())) {
            throw new BusinessRuleException('Pemilik reseller yang sudah memiliki toko/transaksi tidak boleh diganti.');
        }
    }

    public function created(Model $model): void
    {
        $this->record($model, 'created', ['attributes' => $this->safe($model, $model->getAttributes())]);
    }

    public function updated(Model $model): void
    {
        $changes = $this->safe($model, $model->getChanges());

        if ($changes !== []) {
            $this->record($model, 'updated', ['attributes' => $changes, 'old' => $this->safe($model, Arr::only($model->getRawOriginal(), array_keys($changes)))]);
        }
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', ['old' => $this->safe($model, $model->getAttributes())]);
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function safe(Model $model, array $values): array
    {
        return Arr::except($values, [...$model->getHidden(), 'password', 'remember_token', 'created_at', 'updated_at']);
    }

    /** @param array<string, mixed> $properties */
    private function record(Model $model, string $event, array $properties): void
    {
        if (app()->resolved(SalesDashboardService::class)) {
            app(SalesDashboardService::class)->forget();
        }
        $properties += ['origin' => app()->runningInConsole() ? 'console' : 'web'];
        $logger = activity('business')->causedBy(auth()->user())->event($event);

        // activity_log.subject_id berupa bigint: model berkunci teks (mis. Setting) dicatat lewat properti.
        if (is_int($model->getKey()) || ctype_digit((string) $model->getKey())) {
            $logger->performedOn($model);
        } else {
            $properties += ['subject' => class_basename($model).':'.$model->getKey()];
        }

        $logger->withProperties($properties)->log($event);
    }
}
