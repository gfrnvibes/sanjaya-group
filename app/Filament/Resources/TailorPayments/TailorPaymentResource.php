<?php

namespace App\Filament\Resources\TailorPayments;

use App\Enums\WageType;
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
use Filament\Notifications\Notification;
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

    protected static ?string $pluralModelLabel = 'Pembayaran Upah Jahit & Potong';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('wage_type')
                    ->label('Jenis upah')
                    ->options(WageType::class)
                    ->default(WageType::Jahit->value)
                    ->required()
                    ->live()
                    ->helperText('Upah Jahit = pcs x ongkos JAHIT (ke penjahit). Upah Potong = qty potong x ongkos POTONG (ke tukang potong).')
                    ->afterStateUpdated(fn ($state, Get $get, Set $set, ?TailorPayment $record, $livewire) => self::fillDue($state, $get('work_order_id') ?? ($livewire instanceof TailorPaymentsRelationManager ? $livewire->getOwnerRecord()->getKey() : null), $set, $record)),
                Select::make('tailor_id')
                    ->label('Penjahit / tukang potong')
                    ->relationship('tailor', 'name', fn (Builder $query) => $query->where('is_active', true))
                    ->default(fn ($livewire) => $livewire instanceof TailorPaymentsRelationManager ? $livewire->getOwnerRecord()->tailor_id : null)
                    ->helperText('SPK yang orangnya belum dicatat otomatis dicatat atas nama orang ini (penjahit atau tukang potong, sesuai jenis upah).')
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
                        $column = self::wageType($get('wage_type')) === WageType::Potong ? 'cutter_id' : 'tailor_id';

                        // SPK milik orang ini (penjahit / tukang potong), atau SPK yang orangnya belum dicatat.
                        return $query
                            ->where('status', '!=', WorkOrderStatus::Cancelled)
                            ->when($tailorId, fn (Builder $query) => $query->where(fn (Builder $query) => $query->where($column, $tailorId)->orWhereNull($column)));
                    })
                    ->getOptionLabelFromRecordUsing(fn (WorkOrder $record): string => $record->code.($record->tailor_id === null ? ' (penjahit belum dicatat)' : '').($record->cutter_id === null ? ' (tukang potong belum dicatat)' : ''))
                    ->helperText('Jumlah terisi otomatis = sisa upah SPK sesuai jenis upah (ongkos dari resep model; obras/bordir tidak termasuk).')
                    ->live()
                    ->afterStateUpdated(fn ($state, Get $get, Set $set, ?TailorPayment $record) => self::fillDue($get('wage_type'), $state, $set, $record))
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

    private static function wageType(mixed $state): WageType
    {
        return $state instanceof WageType ? $state : (WageType::tryFrom((string) $state) ?? WageType::Jahit);
    }

    /**
     * Isi Jumlah dengan sisa upah SPK untuk jenis upah yang dipilih.
     */
    private static function fillDue(mixed $type, mixed $workOrderId, Set $set, ?TailorPayment $record): void
    {
        if (filled($workOrderId) && ($workOrder = WorkOrder::find($workOrderId)) !== null) {
            $wage = self::wageType($type);
            $set('amount', $workOrder->laborDue($record?->id, $wage) ?: null);

            if ($workOrder->laborTotal($wage) <= 0) {
                Notification::make()
                    ->title("{$wage->getLabel()} SPK {$workOrder->code} = Rp0")
                    ->body('Resep model belum punya ongkos yang dibayar sebagai '.$wage->getLabel().' (menu Jenis Ongkos Kerja & Resep). Isi Jumlah manual bila perlu.')
                    ->warning()
                    ->send();
            }
        }
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
                    ->label('Penjahit / tukang potong')
                    ->searchable(),
                TextColumn::make('wage_type')
                    ->label('Jenis upah')
                    ->badge(),
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
                    ->label('Penjahit / tukang potong')
                    ->relationship('tailor', 'name')
                    ->preload(),
                SelectFilter::make('wage_type')
                    ->label('Jenis upah')
                    ->options(WageType::class),
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
