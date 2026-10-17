<?php

namespace App\Filament\Resources\Materials\RelationManagers;

use App\Filament\Resources\MaterialPurchases\MaterialPurchaseResource;
use App\Models\MaterialPurchaseItem;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PurchaseItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'purchaseItems';

    protected static ?string $title = 'Riwayat Pembelian';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('purchase.supplier'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('purchase.purchase_date')
                    ->label('Tanggal')
                    ->date(),
                TextColumn::make('purchase.supplier.name')
                    ->label('Supplier')
                    ->placeholder('-'),
                TextColumn::make('qty_pack')
                    ->label('Qty Beli')
                    ->numeric(),
                TextColumn::make('qty_base')
                    ->label('Isi (satuan dasar)')
                    ->numeric(),
                TextColumn::make('total_price')
                    ->label('Total Harga')
                    ->money('IDR', decimalPlaces: 0),
                TextColumn::make('unit_cost')
                    ->label('Harga / Satuan')
                    ->money('IDR', decimalPlaces: 2),
            ])
            ->recordActions([
                Action::make('openPurchase')
                    ->label('Lihat Pembelian')
                    ->url(fn (MaterialPurchaseItem $record): string => MaterialPurchaseResource::getUrl('view', ['record' => $record->purchase_id])),
            ]);
    }
}
