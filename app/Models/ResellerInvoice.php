<?php

namespace App\Models;

use App\Enums\ResellerInvoiceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * TAGIHAN konveksi -> reseller per periode.
 */
#[Fillable(['reseller_id', 'invoice_no', 'period_start', 'period_end', 'issued_at', 'due_date', 'status', 'note', 'created_by'])]
class ResellerInvoice extends Model
{
    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'issued_at' => 'date',
            'due_date' => 'date',
            'status' => ResellerInvoiceStatus::class,
        ];
    }

    /** @return BelongsTo<Reseller, $this> */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class)->withTrashed();
    }

    /** @return HasMany<SalesOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    /** @return HasMany<SalesReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function billedTotal(): float
    {
        return (float) $this->items()->sum('bill_amount');
    }

    public function returnsCreditTotal(): float
    {
        return (float) $this->returns()
            ->join('sales_order_items', 'sales_order_items.id', '=', 'sales_returns.sales_order_item_id')
            ->sum(DB::raw('sales_returns.qty * sales_order_items.reseller_unit_price'));
    }

    public function netTotal(): float
    {
        return $this->billedTotal() - $this->returnsCreditTotal();
    }
}
