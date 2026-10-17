<?php

namespace App\Models;

use App\Enums\MaterialMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * LEDGER bahan (append-only). UPDATE/DELETE ditolak trigger; koreksi = baris baru.
 * qty: + masuk, - keluar. Saldo di material_balances diperbarui trigger.
 */
#[Fillable(['material_id', 'warehouse_id', 'moved_at', 'qty', 'movement_type', 'unit_cost', 'source_type', 'source_id', 'note', 'created_by'])]
class MaterialMovement extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'moved_at' => 'datetime',
            'qty' => 'decimal:3',
            'movement_type' => MaterialMovementType::class,
            'unit_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Material, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
