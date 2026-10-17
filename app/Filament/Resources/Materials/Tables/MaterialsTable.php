<?php

namespace App\Filament\Resources\Materials\Tables;

use App\Models\Material;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MaterialsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['materialType', 'color'])
                ->withSum('balances as stock_qty', 'qty'))
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('materialType.name')
                    ->label('Jenis')
                    ->sortable(),
                TextColumn::make('color.name')
                    ->label('Warna')
                    ->placeholder('-'),
                TextColumn::make('stock_qty')
                    ->label('Stok')
                    ->formatStateUsing(fn ($state, Material $record): string => number_format((float) $state, 0, ',', '.')." {$record->base_uom->value}")
                    ->badge()
                    ->color(fn ($state, Material $record): string => (float) $state <= (float) $record->min_stock ? 'danger' : 'success')
                    ->sortable(),
                TextColumn::make('stock_pack')
                    ->label('Setara')
                    ->state(fn (Material $record): string => number_format((float) $record->stock_qty / (float) $record->qty_per_pack, 2, ',', '.')." {$record->pack_uom}"),
                TextColumn::make('min_stock')
                    ->label('Minimum')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('materialType')
                    ->label('Jenis')
                    ->relationship('materialType', 'name')
                    ->preload(),
                Filter::make('below_min')
                    ->label('Stok di bawah minimum')
                    ->query(fn (Builder $query): Builder => $query->whereRaw(
                        'COALESCE((SELECT SUM(qty) FROM material_balances b WHERE b.material_id = materials.id), 0) <= materials.min_stock'
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('stockEntry')
                    ->label('Stok Awal / Koreksi')
                    ->icon(Heroicon::OutlinedAdjustmentsVertical)
                    ->color('gray')
                    ->schema([
                        Radio::make('type')
                            ->label('Jenis')
                            ->options([
                                'opening' => 'Stok awal (+)',
                                'adjustment' => 'Koreksi (+/-)',
                            ])
                            ->default('opening')
                            ->inline()
                            ->required(),
                        Select::make('warehouse_id')
                            ->label('Gudang')
                            ->options(fn () => Warehouse::query()->where('is_active', true)->pluck('name', 'id'))
                            ->required(),
                        TextInput::make('qty')
                            ->label('Qty (satuan dasar)')
                            ->numeric()
                            ->required()
                            ->notIn([0]),
                        TextInput::make('unit_cost')
                            ->label('Harga / Satuan Dasar')
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
                    ->action(function (Material $record, array $data, InventoryService $inventory): void {
                        if ($data['type'] === 'opening') {
                            $inventory->openingMaterial($record->id, (int) $data['warehouse_id'], $data['qty'], $data['unit_cost'] ?? null, $data['moved_at'], $data['note'] ?? null);
                        } else {
                            $inventory->adjustMaterial($record->id, (int) $data['warehouse_id'], $data['qty'], $data['moved_at'], $data['note'] ?? null);
                        }

                        Notification::make()->title('Stok bahan dicatat')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
