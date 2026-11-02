<?php

namespace App\Models;

use App\Enums\StockOpnameStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OPNAME BAHAN BAKU per gudang bahan. Saat posted, selisih -> material_movements(opname).
 * is_opening = opname awal: selisihnya dijurnal ke Saldo Awal (bukan laba rugi).
 */
#[Fillable(['warehouse_id', 'counted_at', 'status', 'posted_at', 'is_opening', 'note', 'created_by'])]
class MaterialOpname extends Model
{
    protected $attributes = [
        'status' => 'draft',
        'is_opening' => false,
    ];

    protected function casts(): array
    {
        return [
            'counted_at' => 'datetime',
            'posted_at' => 'datetime',
            'is_opening' => 'boolean',
            'status' => StockOpnameStatus::class,
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<MaterialOpnameItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MaterialOpnameItem::class, 'opname_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
