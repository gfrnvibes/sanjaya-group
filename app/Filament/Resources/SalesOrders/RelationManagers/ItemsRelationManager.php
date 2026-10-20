<?php

namespace App\Filament\Resources\SalesOrders\RelationManagers;

use App\Enums\ReturnReason;
use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\SalesOrderItem;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Produk';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['variant', 'invoice'])
                ->withSum('returns as returned_qty', 'qty'))
            ->columns([
                TextColumn::make('variant.sku')
                    ->label('SKU')
                    ->description(fn (SalesOrderItem $record): ?string => $record->sku_raw && $record->sku_raw !== $record->variant->sku ? 'Export: '.$record->sku_raw : null),
                TextColumn::make('qty')
                    ->label('Qty')
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('returned_qty')
                    ->label('Retur')
                    ->placeholder('0')
                    ->color('warning'),
                TextColumn::make('revenue')
                    ->label('Pendapatan')
                    ->money('IDR', decimalPlaces: 0)
                    ->placeholder('-')
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('reseller_unit_price')
                    ->label('Harga Reseller')
                    ->money('IDR', decimalPlaces: 0)
                    ->description(fn (SalesOrderItem $record): string => 'Tier '.number_format((float) $record->tier_adjustment_pct * 100, 2, ',', '.').'%'),
                TextColumn::make('bill_amount')
                    ->label('Tagihan')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('margin')
                    ->label('Laba Toko')
                    ->money('IDR', decimalPlaces: 0)
                    ->placeholder('-')
                    ->color(fn ($state): string => (float) $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('hpp_unit')
                    ->label('HPP / pcs')
                    ->money('IDR', decimalPlaces: 0)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('invoice.invoice_no')
                    ->label('Tagihan No.')
                    ->placeholder('Belum ditagih')
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('return')
                    ->label('Retur')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->visible(fn (SalesOrderItem $record): bool => $this->getOwnerRecord()->status !== SalesOrderStatus::Cancelled
                        && (int) $record->returned_qty < $record->qty)
                    ->schema(fn (SalesOrderItem $record): array => [
                        DatePicker::make('returned_at')
                            ->label('Tanggal Retur')
                            ->default(today())
                            ->required(),
                        TextInput::make('qty')
                            ->label('Qty Retur')
                            ->integer()
                            ->minValue(1)
                            ->maxValue($record->qty - (int) $record->returned_qty)
                            ->default($record->qty - (int) $record->returned_qty)
                            ->required(),
                        Select::make('reason')
                            ->label('Alasan Retur')
                            ->options(ReturnReason::class)
                            ->required(),
                        Toggle::make('restock')
                            ->label('Barang kembali ke stok')
                            ->default(true)
                            ->live(),
                        Select::make('warehouse_id')
                            ->label('Gudang')
                            ->options(fn (): array => Warehouse::goodsOptions())
                            ->default(fn (): int => $this->getOwnerRecord()->warehouse_id)
                            ->required(fn (Get $get): bool => (bool) $get('restock'))
                            ->visible(fn (Get $get): bool => (bool) $get('restock')),
                        Toggle::make('credit_reseller')
                            ->label('Kurangi tagihan reseller')
                            ->default(true),
                        TextInput::make('note')
                            ->label('Catatan')
                            ->maxLength(255),
                    ])
                    ->action(function (SalesOrderItem $record, array $data, SalesOrderService $service): void {
                        try {
                            $service->registerReturn($record, $data);
                        } catch (BusinessRuleException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Retur dicatat')->success()->send();
                    }),
            ]);
    }
}
