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
            'price_rounding' => 'Pembulatan usulan harga dasar (mis. 500)',
            'tier_price_rounding' => 'Pembulatan harga reseller setelah tier (mis. 100)',
            'price_tolerance_pct' => 'Toleransi selisih harga sebelum diusulkan (0.02 = 2%)',
            'analysis_days' => 'Hari analisis penjualan (terlaris)',
            'target_stock_days' => 'Target hari stok',
            'lead_time_days' => 'Lead time produksi (hari)',
            'production_batch' => 'Kelipatan qty produksi',
            'default_warehouse_id' => 'ID gudang default',
        ];
    }

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
                    ->unique(ignoreRecord: true),
                TextInput::make('value')
                    ->label('Nilai')
                    ->required()
                    ->numeric(),
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
