<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Support\ChartOfAccounts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AKUN di bagan akun. ledger: konveksi / toko (semua toko memakai bagan akun toko yang sama).
 * role = pengikat aturan jurnal otomatis (akun ber-role tidak boleh dihapus).
 */
#[Fillable(['ledger', 'code', 'name', 'type', 'normal_balance', 'role', 'cash_account_id', 'cash_flow', 'is_active', 'description'])]
class Account extends Model
{
    protected $attributes = [
        'ledger' => ChartOfAccounts::KONVEKSI,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (Account $account): void {
            $account->normal_balance ??= ChartOfAccounts::normalBalance($account->type);
        });

        static::deleting(function (Account $account): void {
            if ($account->role !== null || $account->cash_account_id !== null) {
                throw new BusinessRuleException('Akun sistem / akun rekening tidak boleh dihapus (boleh diganti nama atau kodenya).');
            }

            if ($account->lines()->exists() || ExpenseCategory::query()->where('account_id', $account->id)->exists()) {
                throw new BusinessRuleException('Akun yang sudah dipakai jurnal atau kategori biaya tidak boleh dihapus. Nonaktifkan saja.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public static function idForRole(string $role, string $ledger = ChartOfAccounts::KONVEKSI): int
    {
        return (int) (static::query()->where('ledger', $ledger)->where('role', $role)->value('id')
            ?? throw new BusinessRuleException("Akun dengan peran \"{$role}\" tidak ditemukan di bagan akun."));
    }

    /**
     * Akun kas untuk rekening (dibuat otomatis bila belum ada).
     */
    public static function forCashAccount(CashAccount $cash): self
    {
        return static::query()->firstOrCreate(['cash_account_id' => $cash->id], [
            'ledger' => ChartOfAccounts::KONVEKSI,
            'code' => static::nextCode(ChartOfAccounts::KONVEKSI, '1-11', 1, 98),
            'name' => 'Kas - '.$cash->name,
            'type' => 'asset',
        ]);
    }

    /**
     * Kode berikutnya dalam rentang, mis. 1-1101..1-1198.
     */
    public static function nextCode(string $ledger, string $prefix, int $from, int $to, int $width = 2): string
    {
        $used = static::query()->where('ledger', $ledger)->where('code', 'like', $prefix.'%')->pluck('code')->all();

        for ($number = $from; $number <= $to; $number++) {
            $code = $prefix.str_pad((string) $number, $width, '0', STR_PAD_LEFT);

            if (! in_array($code, $used, true)) {
                return $code;
            }
        }

        throw new BusinessRuleException("Kode akun {$prefix}xx sudah penuh; tambah akun secara manual.");
    }

    /** @param Builder<Account> $query */
    public function scopeLedger(Builder $query, string $ledger): void
    {
        $query->where('ledger', $ledger);
    }

    public function isCash(): bool
    {
        return $this->cash_account_id !== null || in_array($this->role, ['cash_unassigned', 'store_cash'], true);
    }

    public function label(): string
    {
        return $this->code.' '.$this->name;
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
