<?php

namespace App\Filament\Resources\ProductModels\Tables;

use App\Enums\ProductKind;
use App\Models\ProductModel;
use App\Models\Supplier;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProductModelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'supplier'])->withCount(['variants', 'boms']))
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Kategori')
                    ->sortable(),
                TextColumn::make('kind')
                    ->label('Jenis')
                    ->badge(),
                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->placeholder(fn (ProductModel $record): string => $record->kind === ProductKind::BeliJadi ? 'Belum diisi' : '-')
                    ->color(fn (ProductModel $record): ?string => $record->kind === ProductKind::BeliJadi && $record->supplier_id === null ? 'danger' : null)
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('variants_count')
                    ->label('Varian')
                    ->badge(),
                TextColumn::make('boms_count')
                    ->label('Versi Resep')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('default_margin_pct')
                    ->label('Margin')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state * 100, 2, ',', '.').'%')
                    ->placeholder('Default')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Kategori')
                    ->relationship('category', 'name')
                    ->preload(),
                SelectFilter::make('kind')
                    ->label('Jenis')
                    ->options(ProductKind::class),
                SelectFilter::make('supplier')
                    ->label('Supplier')
                    ->relationship('supplier', 'name')
                    ->preload(),
                TernaryFilter::make('has_supplier')
                    ->label('Supplier terisi')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('supplier_id'),
                        false: fn (Builder $query) => $query->whereNull('supplier_id'),
                    ),
                TernaryFilter::make('is_active')
                    ->label('Aktif'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('setSupplier')
                        ->label('Atur supplier')
                        ->icon(Heroicon::OutlinedTruck)
                        ->modalDescription('Hanya model beli jadi yang diubah. Tagihan barang keluar yang sudah ada ikut pindah ke supplier ini (mode berdasarkan barang keluar).')
                        ->schema([
                            Select::make('supplier_id')
                                ->label('Supplier')
                                ->options(fn (): array => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->required(),
                        ])
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data): void {
                            $models = $records->filter(fn (ProductModel $model): bool => $model->kind === ProductKind::BeliJadi);
                            $models->each(fn (ProductModel $model) => $model->update(['supplier_id' => (int) $data['supplier_id']]));

                            Notification::make()
                                ->title("Supplier diatur untuk {$models->count()} model beli jadi")
                                ->body($records->count() > $models->count() ? ($records->count() - $models->count()).' model produksi dilewati.' : null)
                                ->success()
                                ->send();
                        }),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
