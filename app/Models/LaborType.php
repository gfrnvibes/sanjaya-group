<?php

namespace App\Models;

use App\Enums\WageType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Jenis ongkos kerja: POTONG, JAHIT, BORDIR, OBRAS.
 * wage_type = dibayar sebagai upah jahit / upah potong SPK; NULL = dibayar lewat Biaya Operasional.
 */
#[Fillable(['code', 'name', 'wage_type'])]
class LaborType extends Model
{
    /**
     * Dibuat tanpa menyebut "dibayar sebagai" (seeder, import lama): ditebak dari kode / nama.
     */
    protected static function booted(): void
    {
        static::creating(function (LaborType $type): void {
            if (! array_key_exists('wage_type', $type->getAttributes())) {
                $type->wage_type = self::guessWageType((string) $type->code, (string) $type->name);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'wage_type' => WageType::class,
        ];
    }

    /**
     * Tebak "dibayar sebagai" dari kode / nama (jahit, potong), untuk jenis ongkos baru dari import.
     */
    public static function guessWageType(string $code, string $name): ?WageType
    {
        $text = Str::lower($code.' '.$name);

        return match (true) {
            str_contains($text, 'jahit') => WageType::Jahit,
            str_contains($text, 'potong') => WageType::Potong,
            default => null,
        };
    }

    /** @return HasMany<BomItem, $this> */
    public function bomItems(): HasMany
    {
        return $this->hasMany(BomItem::class);
    }
}
