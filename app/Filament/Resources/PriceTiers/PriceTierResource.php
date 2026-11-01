<?php

namespace App\Filament\Resources\PriceTiers;

use App\Filament\Resources\PriceTiers\Pages\ManagePriceTiers;
use App\Filament\Tables\Filters\DateRangeFilter;
use App\Models\PriceTier;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class PriceTierResource extends Resource
{
    protected static ?string $model = PriceTier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Reseller & Toko';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Tier Harga';

    protected static ?string $pluralModelLabel = 'Tier Harga';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kode')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->maxLength(60),
                TextInput::make('adjustment_pct')
                    ->label('Penyesuaian dari Harga Dasar')
                    ->helperText('5 = harga dasar +5%, -3 = harga dasar -3%. Langsung berlaku: harga penjualan pemilik tier ini yang belum masuk tagihan terbit otomatis disesuaikan.')
                    ->numeric()
                    ->suffix('%')
                    ->default(0)
                    ->required()
                    ->formatStateUsing(fn ($state): ?float => $state === null ? null : (float) $state * 100)
                    ->dehydrateStateUsing(fn ($state): float => (float) $state / 100),
            ]);
    }

    public static function table(Table $table): Table
    {
        $money = fn (string $name, string $label): TextColumn => TextColumn::make($name)
            ->label($label)
            ->money('IDR', decimalPlaces: 0)
            ->alignEnd()
            ->sortable()
            ->summarize(Sum::make()->label('Total')->money('IDR', decimalPlaces: 0));

        return $table
            ->modifyQueryUsing(fn (Builder $query, HasTable $livewire): Builder => self::withRevenue(
                $query,
                ...DateRangeFilter::resolve($livewire->tableFilters['period'] ?? []),
            )->withCount('ownerTierHistories'))
            ->description('Pendapatan dari penjualan (selain batal, retur penuh & titipan) menurut tier pemilik pada tanggal pesanan. Pendapatan tier = selisih harga reseller dari harga dasar.')
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable(),
                TextColumn::make('adjustment_pct')
                    ->label('Penyesuaian')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state * 100, 2, ',', '.').'%')
                    ->sortable(),
                TextColumn::make('owners_now')
                    ->label('Pemilik aktif')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('qty_sold')
                    ->label('Pcs terjual')
                    ->numeric(locale: 'id')
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')->numeric(locale: 'id')),
                TextColumn::make('tier_per_pcs')
                    ->label('Nominal tier / pcs')
                    ->state(fn (PriceTier $record): float => (int) $record->qty_sold > 0 ? (float) $record->tier_amount / (int) $record->qty_sold : 0)
                    ->money('IDR', decimalPlaces: 0)
                    ->alignEnd()
                    ->tooltip('Rata-rata selisih harga reseller dari harga dasar per pcs'),
                $money('base_amount', 'Pendapatan harga dasar')->toggleable(isToggledHiddenByDefault: true),
                $money('tier_amount', 'Pendapatan tier')
                    ->color(fn ($state): ?string => (float) $state < 0 ? 'danger' : 'success'),
                $money('konveksi_amount', 'Pendapatan konveksi')->weight('bold'),
                TextColumn::make('owner_tier_histories_count')
                    ->label('Dipakai (riwayat)')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Hanya membatasi periode penjualan yang dihitung (lihat withRevenue), tidak menyaring tier.
                DateRangeFilter::make('period', 'Periode penjualan', fn (Builder $query): Builder => $query),
            ])
            ->deferFilters(false)
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Tambah kolom pemilik aktif & pendapatan per tier (tier pemilik yang berlaku pada tanggal pesanan).
     *
     * @param  Builder<PriceTier>  $query
     * @return Builder<PriceTier>
     */
    public static function withRevenue(Builder $query, ?string $from = null, ?string $until = null): Builder
    {
        $sales = DB::table('sales_order_items as i')
            ->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')
            ->join('stores as st', 'st.id', '=', 'o.store_id')
            ->join('resellers as r', 'r.id', '=', 'st.reseller_id')
            ->join('reseller_price_histories as rp', 'rp.id', '=', 'i.reseller_price_id')
            ->joinLateral(
                DB::table('owner_tier_histories as h')
                    ->select('h.price_tier_id')
                    ->whereColumn('h.owner_id', 'r.owner_id')
                    ->whereColumn('h.effective_from', '<=', 'o.order_date')
                    ->orderByDesc('h.effective_from')
                    ->limit(1),
                't',
            )
            ->whereNotIn('o.status', ['cancelled', 'returned'])
            ->where('i.is_consignment', false)
            ->when($from, fn ($sales) => $sales->whereDate('o.order_date', '>=', $from))
            ->when($until, fn ($sales) => $sales->whereDate('o.order_date', '<=', $until))
            ->groupBy('t.price_tier_id')
            ->select('t.price_tier_id')
            ->selectRaw('SUM(i.qty) AS qty_sold, SUM(i.qty * rp.base_price) AS base_amount, SUM(i.bill_amount) AS konveksi_amount');

        // pemilik yang tier terakhirnya (berlaku s.d. hari ini) adalah tier ini
        $ownersNow = DB::table('owners as ow')
            ->selectRaw('COUNT(*)')
            ->whereRaw('(SELECT h.price_tier_id FROM owner_tier_histories h WHERE h.owner_id = ow.id AND h.effective_from <= CURRENT_DATE ORDER BY h.effective_from DESC LIMIT 1) = price_tiers.id');

        return $query
            ->leftJoinSub($sales, 'sales', 'sales.price_tier_id', '=', 'price_tiers.id')
            ->select('price_tiers.*')
            ->selectSub($ownersNow, 'owners_now')
            ->selectRaw('COALESCE(sales.qty_sold, 0) AS qty_sold')
            ->selectRaw('COALESCE(sales.base_amount, 0) AS base_amount')
            ->selectRaw('COALESCE(sales.konveksi_amount, 0) - COALESCE(sales.base_amount, 0) AS tier_amount')
            ->selectRaw('COALESCE(sales.konveksi_amount, 0) AS konveksi_amount');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePriceTiers::route('/'),
        ];
    }
}
