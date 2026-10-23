<?php

namespace App\Filament\Resources\Settings;

use App\Filament\Resources\Settings\Pages\ManageSettings;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class SettingResource extends Resource
{
    protected static ?string $model = Setting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Pengaturan';

    protected static ?string $pluralModelLabel = 'Pengaturan';

    protected static ?string $recordTitleAttribute = 'key';

    /**
     * Kunci pengaturan yang dipakai aplikasi beserta penjelasannya.
     *
     * @return array<string, string>
     */
    public static function knownKeys(): array
    {
        return [
            'margin_default' => 'Margin default usulan harga (0.30 = 30%)',
            'margin_ready_made_pct' => 'Margin % default produk beli jadi (0.05 = 5%)',
            'packing_cost_default' => 'Biaya packing default produk beli jadi, Rp per pcs (bisa diubah per varian)',
            'packing_cost_default_production' => 'Biaya packing default produk produksi, Rp per pcs (bisa diubah per varian, dengan tanggal berlaku)',
            'price_rounding' => 'Pembulatan usulan harga dasar (mis. 500)',
            'tier_price_rounding' => 'Pembulatan harga reseller setelah tier (mis. 100)',
            'price_tolerance_pct' => 'Toleransi selisih harga sebelum diusulkan (0.02 = 2%)',
            'analysis_days' => 'Hari analisis penjualan (terlaris)',
            'target_stock_days' => 'Target hari stok',
            'lead_time_days' => 'Lead time produksi (hari)',
            'production_batch' => 'Kelipatan qty produksi',
            'default_warehouse_id' => 'ID gudang default barang jadi (penjualan, barang masuk, retur, hasil SPK)',
            'default_material_warehouse_id' => 'ID gudang default bahan baku (pembelian, stok awal bahan, potong SPK)',
            'dead_stock_days' => 'Stok lambat laku bila tidak terjual (hari)',
            'return_alert_pct' => 'Batas peringatan % retur (0.10 = 10%)',
            'return_alert_min_qty' => 'Minimal qty retur untuk peringatan (pcs)',
            'report_emails' => 'Email penerima laporan mingguan (pisahkan koma)',
        ];
    }

    /**
     * Pengaturan berisi teks (bukan angka).
     */
    public const TEXT_KEYS = ['report_emails'];

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('key')
                    ->label('Kunci')
                    ->options(static::knownKeys())
                    ->searchable()
                    ->required()
                    ->disabledOn('edit')
                    ->unique(ignoreRecord: true)
                    ->live(),
                TextInput::make('value')
                    ->label('Nilai')
                    ->required()
                    ->numeric(fn (Get $get): bool => ! in_array($get('key'), static::TEXT_KEYS, true))
                    ->minValue(fn (Get $get): ?float => in_array($get('key'), static::TEXT_KEYS, true) ? null : (in_array($get('key'), ['price_rounding', 'tier_price_rounding', 'analysis_days', 'production_batch', 'dead_stock_days', 'default_warehouse_id', 'default_material_warehouse_id'], true) ? 1 : 0)),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('key')
                    ->label('Kunci')
                    ->searchable()
                    ->description(fn (Setting $record): ?string => static::knownKeys()[$record->key] ?? null),
                TextColumn::make('value')
                    ->label('Nilai'),
                TextColumn::make('updated_at')
                    ->label('Diubah')
                    ->dateTime(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSettings::route('/'),
        ];
    }
}
