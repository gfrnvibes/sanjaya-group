<?php

namespace App\Models;

use App\Enums\ReturnReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Retur sebagian / penuh. restock -> stock_movements(return_in) ke warehouse_id;
 * credit_reseller -> mengurangi tagihan reseller.
 */
#[Fillable(['sales_order_item_id', 'returned_at', 'qty', 'reason', 'restock', 'warehouse_id', 'credit_reseller', 'reseller_invoice_id', 'note', 'created_by'])]
class SalesReturn extends Model
{
    protected function casts(): array
    {
        return [
            'returned_at' => 'date',
            'qty' => 'integer',
            'reason' => ReturnReason::class,
            'restock' => 'boolean',
            'credit_reseller' => 'boolean',
        ];
    }

    /** @return BelongsTo<SalesOrderItem, $this> */
    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<ResellerInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ResellerInvoice::class, 'reseller_invoice_id');
    }

    /** @return MorphMany<StockMovement, $this> */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
