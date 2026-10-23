<?php

namespace App\Observers;

use App\Enums\StockOpnameStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Reseller;
use App\Models\Setting;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\Store;
use App\Services\SalesDashboardService;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class BusinessRecordObserver
{
    private function ensureOpnameEditable(Model $model): void
    {
        $id = $model instanceof StockOpname ? $model->getKey() : ($model instanceof StockOpnameItem ? $model->opname_id : null);
        if ($id !== null) {
            $parent = StockOpname::query()->lockForUpdate()->find($id);
            if ($parent?->status === StockOpnameStatus::Posted) {
                throw new BusinessRuleException('Opname sudah diposting dan tidak boleh diubah/dihapus.');
            }
        }
    }

    public function deleting(Model $model): void
    {
        $this->ensureOpnameEditable($model);
    }

    public function saving(Model $model): void
    {
        $this->ensureOpnameEditable($model);
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
        if ($model instanceof Setting && ! in_array($model->key, ['report_emails', 'supplier_billing_start'], true)) {
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
