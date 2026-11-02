<?php

namespace App\Models;

use App\Support\ChartOfAccounts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * KATEGORI BIAYA -> akun buku besar (kosong = akun baru dibuat otomatis dengan nama kategori).
 */
#[Fillable(['code', 'name', 'account_id'])]
class ExpenseCategory extends Model
{
    protected static function booted(): void
    {
        static::creating(function (ExpenseCategory $category): void {
            $category->account_id ??= Account::query()->create([
                'code' => Account::nextCode(ChartOfAccounts::KONVEKSI, '6-1', 101, 899, 3),
                'name' => 'Beban '.$category->name,
                'type' => 'expense',
                'cash_flow' => 'operating',
            ])->id;
        });
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id');
    }
}
