<?php

namespace App\Filament\Resources\SalesImportBatches\Tables;

use App\Enums\ImportBatchStatus;
use App\Enums\SalesImportSource;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SalesImportBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('importer')->withCount('salesOrders'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('file_name')
                    ->label('File')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('source')
                    ->label('Sumber')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (ImportBatchStatus $state): string => match ($state) {
                        ImportBatchStatus::Pending => 'warning',
                        ImportBatchStatus::Done => 'success',
                        ImportBatchStatus::Failed => 'danger',
                    }),
                TextColumn::make('row_count')
                    ->label('Baris'),
                TextColumn::make('sales_orders_count')
                    ->label('Pesanan'),
                TextColumn::make('error_count')
                    ->label('Error')
                    ->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('importer.name')
                    ->label('Oleh')
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Sumber')
                    ->options(SalesImportSource::class),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ImportBatchStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
