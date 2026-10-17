<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengganti sheet PENGATURAN (key -> nilai jsonb).
 */
#[Table(key: 'key', keyType: 'string', incrementing: false)]
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public const CREATED_AT = null;

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public static function valueOf(string $key, mixed $default = null): mixed
    {
        return static::query()->find($key)?->value ?? $default;
    }
}
