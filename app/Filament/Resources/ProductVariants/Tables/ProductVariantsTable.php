<?php

namespace App\Filament\Resources\ProductVariants\Tables;

use App\Exceptions\BusinessRuleException;
use App\Models\ProductVariant;
use App\Services\CostingService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProductVariantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['model', 'color', 'size', 'pricing'])
                ->withSum('stockBalances as stock_qty', 'qty'))
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('model.code')
                    ->label('Model')
                    ->toggleable(),
                TextColumn::make('color.name')
                    ->label('Warna')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('size.code')
                    ->label('Ukuran')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('stock_qty')
                    ->label('Stok')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (ProductVariant $record, $state): string => (int) $state <= $record->min_stock ? 'danger' : 'success'),
                TextColumn::make('pricing.hpp')
                    ->label('HPP')
                    ->money('IDR', decimalPlaces: 0)
                    ->placeholder('-'),
                TextColumn::make('pricing.current_price')
                    ->label('Harga Dasar')
                    ->money('IDR', decimalPlaces: 0)
                    ->placeholder('-'),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('model')
                    ->label('Model')
                    ->relationship('model', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('color')
                    ->label('Warna')
                    ->relationship('color', 'name')
                    ->preload(),
                SelectFilter::make('size')
                    ->label('Ukuran')
                    ->relationship('size', 'code')
                    ->preload(),
                Filter::make('below_min')
                    ->label('Stok di bawah minimum')
                    ->query(fn (Builder $query): Builder => $query->whereRaw(
                        'COALESCE((SELECT SUM(qty) FROM stock_balances b WHERE b.variant_id = product_variants.id), 0) <= product_variants.min_stock'
                    )),
                TernaryFilter::make('is_active')
                    ->label('Aktif'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('refreshCost')
                    ->label('Hitung HPP')
                    ->icon(Heroicon::OutlinedCalculator)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Hitung HPP dari resep (BOM) x harga beli bahan terakhir, simpan sebagai HPP berlaku hari ini.')
                    ->action(function (ProductVariant $record, CostingService $costing): void {
                        try {
                            $cost = $costing->refreshVariantCost($record);
                        } catch (BusinessRuleException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('HPP '.$record->sku.' = Rp '.number_format((float) $cost->fresh()->total_cost, 0, ',', '.'))
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('refreshCosts')
                        ->label('Hitung HPP dari BOM')
                        ->icon(Heroicon::OutlinedCalculator)
                        ->requiresConfirmation()
                        ->action(function (Collection $records, CostingService $costing): void {
                            $failed = [];

                            foreach ($records as $record) {
                                try {
                                    $costing->refreshVariantCost($record);
                                } catch (BusinessRuleException $exception) {
                                    $failed[] = $exception->getMessage();
                                }
                            }

                            Notification::make()
                                ->title(($records->count() - count($failed)).' HPP diperbarui')
                                ->body($failed === [] ? null : implode("\n", array_slice($failed, 0, 5)))
                                ->color($failed === [] ? 'success' : 'warning')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
