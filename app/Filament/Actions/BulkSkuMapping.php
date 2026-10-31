<?php

namespace App\Filament\Actions;

use App\Enums\SalesOrderStatus;
use App\Filament\Resources\SalesImportFailures\Pages\ListSalesImportFailures;
use App\Imports\ImportRegistry;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\SkuMapping;
use App\Services\SkuMatcher;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;

/**
 * Isi modal PETAKAN SKU MASSAL: 1 baris per SKU marketplace yang gagal, varian terisi saran terbaik.
 * Dipakai tombol header (semua SKU gagal) dan bulk action tabel (hanya baris yang dicentang).
 */
class BulkSkuMapping
{
    /**
     * @param  list<int>|null  $failureIds  null = semua baris gagal karena SKU
     * @return array<string, mixed>
     */
    public static function fill(?array $failureIds = null): array
    {
        return [
            'select' => 'confident',
            'skus' => collect(app(SkuMapping::class)->unmappedSkus($failureIds))->map(fn (array $sku): array => [
                'apply' => ($sku['suggestion']['score'] ?? 0) >= SkuMatcher::HIGH_CONFIDENCE,
                'alias' => $sku['alias'],
                'item' => collect([$sku['product'], $sku['variation']])->filter()->implode(' / ') ?: '-',
                'count' => $sku['count'],
                'variant_id' => $sku['suggestion']['variant']->id ?? null,
                'score' => $sku['suggestion'] === null ? 'Tidak ada saran' : $sku['suggestion']['score'].'%',
                'confident' => ($sku['suggestion']['score'] ?? 0) >= SkuMatcher::HIGH_CONFIDENCE,
            ])->all(),
            'warehouse_id' => Warehouse::defaultForGoods()?->id,
            'status' => SalesOrderStatus::Completed->value,
        ];
    }

    /**
     * @return list<Component>
     */
    public static function schema(): array
    {
        return [
            ToggleButtons::make('select')
                ->label('Centang')
                ->options([
                    'all' => 'Pilih semua',
                    'confident' => 'Saran yakin saja (>= '.SkuMatcher::HIGH_CONFIDENCE.'%)',
                    'none' => 'Kosongkan',
                ])
                ->inline()
                ->live()
                ->dehydrated(false)
                ->helperText('Pilih semua mencentang setiap SKU yang variannya sudah terisi; SKU tanpa varian dicentang setelah variannya dipilih.')
                ->afterStateUpdated(fn (?string $state, Get $get, Set $set) => $set('skus', collect($get('skus') ?? [])
                    ->map(fn (array $row): array => [...$row, 'apply' => match ($state) {
                        'all' => filled($row['variant_id'] ?? null),
                        'confident' => (bool) ($row['confident'] ?? false) && filled($row['variant_id'] ?? null),
                        default => false,
                    }])
                    ->all())),
            Repeater::make('skus')
                ->hiddenLabel()
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->table([
                    TableColumn::make('Petakan'),
                    TableColumn::make('SKU marketplace'),
                    TableColumn::make('Nama produk / variasi'),
                    TableColumn::make('Baris'),
                    TableColumn::make('Varian sistem'),
                    TableColumn::make('Skor saran'),
                ])
                ->schema([
                    Checkbox::make('apply')->live(),
                    TextInput::make('alias')->readOnly(),
                    TextInput::make('item')->readOnly()->dehydrated(false),
                    TextInput::make('count')->readOnly()->dehydrated(false),
                    Select::make('variant_id')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => ProductVariant::query()
                            ->where('is_active', true)
                            ->where(fn (Builder $query) => $query->where('sku', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"))
                            ->orderBy('sku')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (ProductVariant $variant): array => [$variant->id => "{$variant->sku} - {$variant->name}"])
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => ($variant = ProductVariant::find($value)) ? "{$variant->sku} - {$variant->name}" : null)
                        ->placeholder('Ketik SKU / nama')
                        ->live()
                        // Memilih varian = otomatis dicentang (mengosongkan tidak menghapus centang; validasi yang mengingatkan).
                        ->afterStateUpdated(fn ($state, Set $set) => filled($state) ? $set('apply', true) : null)
                        ->required(fn (Get $get): bool => (bool) $get('apply')),
                    TextInput::make('score')->readOnly()->dehydrated(false),
                    Hidden::make('confident')->dehydrated(false),
                ]),
            ...ListSalesImportFailures::categoryOptionFields(ImportRegistry::SALES_ORDERS),
        ];
    }

    /**
     * Simpan pemetaan yang dicentang lalu coba ulang semua baris gagal dengan SKU tersebut.
     *
     * @param  array<string, mixed>  $data
     */
    public static function handle(array $data): void
    {
        $mappings = collect($data['skus'] ?? [])
            ->filter(fn (array $row): bool => ($row['apply'] ?? false) && filled($row['variant_id'] ?? null) && filled($row['alias'] ?? null))
            ->mapWithKeys(fn (array $row): array => [$row['alias'] => (int) $row['variant_id']])
            ->all();

        if ($mappings === []) {
            Notification::make()->title('Belum ada SKU yang dicentang')->warning()->send();

            return;
        }

        $result = app(SkuMapping::class)->mapMany($mappings, ListSalesImportFailures::categoryOptions(ImportRegistry::SALES_ORDERS, $data));

        Notification::make()
            ->title("{$result['mapped']} SKU dipetakan, {$result['fixed']} baris pesanan berhasil disimpan")
            ->body($result['errors'] === [] ? null : $result['failed'].' baris masih gagal (alasan terbaru ada di daftar). '.e(implode(' ', array_slice($result['errors'], 0, 5))))
            ->{$result['errors'] === [] ? 'success' : 'warning'}()
            ->persistent()
            ->send();
    }
}
