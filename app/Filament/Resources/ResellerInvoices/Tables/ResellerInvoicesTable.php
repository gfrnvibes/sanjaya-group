<?php

namespace App\Filament\Resources\ResellerInvoices\Tables;

use App\Enums\ResellerInvoiceStatus;
use App\Filament\Resources\ResellerInvoices\Schemas\ResellerInvoiceInfolist;
use App\Models\ResellerInvoice;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ResellerInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('reseller')
                ->withCount('items')
                ->withSum('items as billed', 'bill_amount'))
            ->defaultSort('period_start', 'desc')
            ->columns([
                TextColumn::make('invoice_no')
                    ->label('No. Tagihan')
                    ->description(fn (ResellerInvoice $record): ?string => $record->is_auto ? 'Otomatis' : null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reseller.name')
                    ->label('Reseller')
                    ->searchable(),
                TextColumn::make('period_start')
                    ->label('Periode')
                    ->formatStateUsing(fn (ResellerInvoice $record): string => $record->period_start->format('d/m/Y').' - '.$record->period_end->format('d/m/Y'))
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (ResellerInvoiceStatus $state): string => ResellerInvoiceInfolist::statusColor($state)),
                TextColumn::make('items_count')
                    ->label('Baris'),
                TextColumn::make('billed')
                    ->label('Penjualan')
                    ->money('IDR', decimalPlaces: 0),
                TextColumn::make('net')
                    ->label('Total Tagihan')
                    ->state(fn (ResellerInvoice $record): float => $record->netTotal())
                    ->money('IDR', decimalPlaces: 0)
                    ->weight('bold'),
                TextColumn::make('issued_at')
                    ->label('Terbit')
                    ->date()
                    ->placeholder('-'),
                TextColumn::make('due_date')
                    ->label('Jatuh Tempo')
                    ->date()
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ResellerInvoiceStatus::class),
                SelectFilter::make('reseller')
                    ->label('Reseller')
                    ->relationship('reseller', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
