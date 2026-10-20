<?php

namespace App\Filament\Resources\StockMovements\Pages;

use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageStockMovements extends ManageRecords
{
    protected static string $resource = StockMovementResource::class;

    public function getSubheading(): ?string
    {
        return 'Ledger append-only: baris tidak bisa diubah/dihapus. Koreksi dilakukan dengan baris baru.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ...ResourceIo::make(),
            Action::make('opening')
                ->label('Stok Awal')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->schema([
                    Select::make('variant_id')
                        ->label('Varian')
                        ->options(fn () => ProductVariant::query()->orderBy('sku')->pluck('sku', 'id'))
                        ->searchable()
                        ->required(),
                    Select::make('warehouse_id')
                        ->label('Gudang')
                        ->options(fn () => Warehouse::query()->where('is_active', true)->pluck('name', 'id'))
                        ->default(fn () => Warehouse::query()->value('id'))
                        ->required(),
                    TextInput::make('qty')
                        ->label('Qty')
                        ->integer()
                        ->minValue(1)
                        ->required(),
                    TextInput::make('unit_cost')
                        ->label('HPP / pcs')
                        ->numeric()
                        ->prefix('Rp'),
                    DatePicker::make('moved_at')
                        ->label('Tanggal')
                        ->default(today())
                        ->required(),
                    TextInput::make('note')
                        ->label('Catatan')
                        ->maxLength(255),
                ])
                ->action(function (array $data, InventoryService $inventory): void {
                    $inventory->openingStock((int) $data['variant_id'], (int) $data['warehouse_id'], (int) $data['qty'], $data['unit_cost'] ?? null, $data['moved_at'], $data['note'] ?? null);

                    Notification::make()->title('Stok awal dicatat')->success()->send();
                }),
        ];
    }
}
