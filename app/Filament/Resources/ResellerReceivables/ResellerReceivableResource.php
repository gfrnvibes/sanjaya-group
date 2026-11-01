<?php

namespace App\Filament\Resources\ResellerReceivables;

use App\Filament\Resources\ResellerReceivables\Pages\ManageResellerReceivables;
use App\Filament\Resources\Resellers\ResellerResource;
use App\Models\ResellerReceivable;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use UnitEnum;

class ResellerReceivableResource extends Resource
{
    protected static ?string $model = ResellerReceivable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Piutang Reseller';

    protected static ?string $pluralModelLabel = 'Piutang Reseller';

    protected static ?string $slug = 'laporan/piutang-reseller';

    public const BALANCE_OUTSTANDING = 'outstanding';

    public const BALANCE_DEPOSIT = 'deposit';

    public const BALANCE_SETTLED = 'settled';

    /** Filter saldo: piutang (> 0), deposit reseller di konveksi (sisa piutang < 0), lunas (= 0). */
    public const BALANCES = [
        self::BALANCE_OUTSTANDING => 'Masih ada piutang',
        self::BALANCE_DEPOSIT => 'Deposit (lebih bayar)',
        self::BALANCE_SETTLED => 'Lunas (saldo 0)',
    ];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('owner'))
            ->defaultSort('outstanding', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Reseller')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('owner.name')
                    ->label('Pemilik'),
                TextColumn::make('opening_balance')
                    ->label('Saldo Awal')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('billed')
                    ->label('Tagihan')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('returns_credit')
                    ->label('Kredit Retur')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('paid')
                    ->label('Dibayar')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('outstanding')
                    ->label('Sisa Piutang')
                    ->money('IDR', decimalPlaces: 0)
                    ->weight('bold')
                    ->color(fn ($state): string => (float) $state > 0 ? 'danger' : 'success')
                    // sisa negatif = reseller membayar lebih: deposit di konveksi
                    ->description(fn (ResellerReceivable $record): ?string => (float) $record->outstanding < 0
                        ? 'Deposit '.Number::currency(-(float) $record->outstanding, 'IDR', 'id', 0)
                        : null)
                    ->sortable()
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
            ])
            ->filters([
                SelectFilter::make('owner')
                    ->label('Pemilik')
                    ->relationship('owner', 'name')
                    ->preload(),
                SelectFilter::make('balance')
                    ->label('Saldo')
                    ->options(self::BALANCES)
                    ->default(self::BALANCE_OUTSTANDING)
                    ->placeholder('Semua reseller')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        self::BALANCE_OUTSTANDING => $query->where('outstanding', '>', 0),
                        self::BALANCE_DEPOSIT => $query->where('outstanding', '<', 0),
                        self::BALANCE_SETTLED => $query->where('outstanding', 0),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('openReseller')
                    ->label('Detail')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (ResellerReceivable $record): string => ResellerResource::getUrl('view', ['record' => $record->reseller_id])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageResellerReceivables::route('/'),
        ];
    }
}
