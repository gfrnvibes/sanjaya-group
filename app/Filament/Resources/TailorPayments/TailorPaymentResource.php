<?php

namespace App\Filament\Resources\TailorPayments;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\TailorPayments\Pages\ManageTailorPayments;
use App\Filament\Resources\Tailors\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\WorkOrders\RelationManagers\TailorPaymentsRelationManager;
use App\Models\TailorPayment;
use App\Models\WorkOrder;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TailorPaymentResource extends Resource
{
    protected static ?string $model = TailorPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static string|UnitEnum|null $navigationGroup = 'Produksi';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Pembayaran Upah';

    protected static ?string $pluralModelLabel = 'Pembayaran Upah Jahit';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('tailor_id')
                    ->label('Penjahit')
                    ->relationship('tailor', 'name', fn (Builder $query) => $query->where('is_active', true))
                    ->default(fn ($livewire) => $livewire instanceof TailorPaymentsRelationManager ? $livewire->getOwnerRecord()->tailor_id : null)
                    ->helperText('SPK yang penjahitnya belum dicatat otomatis dicatat atas nama penjahit ini.')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->hidden(fn ($livewire): bool => $livewire instanceof PaymentsRelationManager),
                Select::make('work_order_id')
                    ->label('SPK')
                    ->relationship('workOrder', 'code', function (Builder $query, Get $get, $livewire): Builder {
                        $tailorId = $get('tailor_id')
                            ?? ($livewire instanceof PaymentsRelationManager ? $livewire->getOwnerRecord()->getKey() : null);

                        // SPK milik penjahit ini, atau SPK yang penjahitnya belum dicatat.
                        return $query
                            ->where('status', '!=', WorkOrderStatus::Cancelled)
                            ->when($tailorId, fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('tailor_id', $tailorId)->orWhereNull('tailor_id')));
                    })
                    ->getOptionLabelFromRecordUsing(fn (WorkOrder $record): string => $record->code.($record->tailor_id === null ? ' (penjahit belum dicatat)' : ''))
                    ->helperText('Jumlah terisi otomatis = sisa upah SPK (pcs x ongkos per pcs dari resep model).')
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set, ?TailorPayment $record): void {
                        if (filled($state) && ($workOrder = WorkOrder::find($state)) !== null) {
                            $set('amount', $workOrder->laborDue($record?->id) ?: null);
                        }
                    })
                    ->searchable()
                    ->preload()
                    ->hidden(fn ($livewire): bool => $livewire instanceof TailorPaymentsRelationManager),
                DatePicker::make('paid_at')
                    ->label('Tanggal Bayar')
                    ->default(today())
                    ->required(),
                TextInput::make('amount')
                    ->label('Jumlah')
                    ->default(fn ($livewire) => $livewire instanceof TailorPaymentsRelationManager ? ($livewire->getOwnerRecord()->laborDue() ?: null) : null)
                    ->numeric()
                    ->minValue(1)
                    ->prefix('Rp')
                    ->required(),
                Select::make('cash_account_id')
                    ->label('Dari Rekening / Kas')
                    ->relationship('cashAccount', 'name')
                    ->preload(),
                TextInput::make('note')
                    ->label('Catatan')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['tailor', 'workOrder', 'cashAccount']))
            ->defaultSort('paid_at', 'desc')
            ->columns([
                TextColumn::make('paid_at')
                    ->label('Tanggal')
                    ->date()
                    ->sortable(),
                TextColumn::make('tailor.name')
                    ->label('Penjahit')
                    ->searchable(),
                TextColumn::make('workOrder.code')
                    ->label('SPK')
                    ->placeholder('-'),
                TextColumn::make('amount')
                    ->label('Jumlah')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('cashAccount.name')
                    ->label('Rekening')
                    ->placeholder('-'),
                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('tailor')
                    ->label('Penjahit')
                    ->relationship('tailor', 'name')
                    ->preload(),
            ])
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

    public static function getPages(): array
    {
        return [
            'index' => ManageTailorPayments::route('/'),
        ];
    }
}
