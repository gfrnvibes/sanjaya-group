<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Basis model untuk VIEW laporan PostgreSQL: hanya dibaca, tidak bisa disimpan/dihapus.
 */
#[WithoutTimestamps]
abstract class ReadOnlyModel extends Model
{
    public $incrementing = false;

    public function save(array $options = []): bool
    {
        throw new LogicException(static::class.' adalah view read-only.');
    }

    public function delete(): ?bool
    {
        throw new LogicException(static::class.' adalah view read-only.');
    }
}
