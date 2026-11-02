<?php

namespace App\Filament\Forms\Components;

use App\Enums\PaymentMethod;
use App\Models\Reseller;
use App\Models\ResellerPayment;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * Pilihan "dibayar reseller" (POTONGAN BIAYA = pembayaran reseller tanpa rekening yang memotong piutangnya).
 * Pilih potongan yang sudah dicatat (tampil sisa yang belum dirinci) atau buat baru dari sini.
 */
class ResellerOffsetSelect
{
    public static function make(string $name = 'reseller_payment_id'): Select
    {
        return Select::make($name)
            ->label('Potongan biaya reseller')
            ->options(fn (): array => self::options())
            ->searchable()
            ->helperText('Belanja / upah yang dibayar reseller memotong piutang reseller itu. Pilih potongan yang sudah dicatat, atau + untuk mencatat baru.')
            ->createOptionForm([
                Select::make('reseller_id')
                    ->label('Dibayar oleh reseller')
                    ->options(fn (): array => Reseller::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                DatePicker::make('paid_at')
                    ->label('Tanggal')
                    ->default(today())
                    ->required(),
                TextInput::make('amount')
                    ->label('Jumlah')
                    ->numeric()
                    ->minValue(1)
                    ->prefix('Rp')
                    ->required(),
                TextInput::make('note')
                    ->label('Catatan')
                    ->maxLength(255),
            ])
            ->createOptionUsing(fn (array $data): int => ResellerPayment::query()->create([
                ...$data,
                'method' => PaymentMethod::CostOffset,
                'created_by' => auth()->id(),
            ])->getKey());
    }

    /**
     * Potongan biaya terbaru dulu, dengan sisa yang belum dirinci ke biaya / pembelian bahan / upah.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return collect(DB::select(<<<'SQL'
            SELECT p.id, r.name, p.paid_at, p.amount, p.note,
                   p.amount - COALESCE((SELECT SUM(amount) FROM expenses WHERE reseller_payment_id = p.id), 0)
                            - COALESCE((SELECT SUM(amount) FROM tailor_payments WHERE reseller_payment_id = p.id), 0)
                            - COALESCE((SELECT SUM(i.total_price) FROM material_purchase_items i JOIN material_purchases m ON m.id = i.purchase_id WHERE m.reseller_payment_id = p.id), 0) AS remaining
            FROM reseller_payments p JOIN resellers r ON r.id = p.reseller_id
            WHERE p.method = 'cost_offset'
            ORDER BY p.paid_at DESC, p.id DESC
            SQL))->mapWithKeys(fn (object $row): array => [(int) $row->id => sprintf(
            '%s · %s · %s%s%s',
            $row->name,
            date('d/m/Y', strtotime($row->paid_at)),
            Number::currency((float) $row->amount, 'IDR', 'id', 0),
            $row->note ? ' ('.$row->note.')' : '',
            (float) $row->remaining != 0 ? ' · belum dirinci '.Number::currency((float) $row->remaining, 'IDR', 'id', 0) : ' · sudah dirinci',
        )])->all();
    }
}
