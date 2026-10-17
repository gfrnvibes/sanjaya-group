<?php

namespace App\Filament\Resources\SalesOrders\Tables;

use App\Enums\SalesOrderStatus;
use App\Filament\Resources\SalesOrders\Schemas\SalesOrderInfolist;
use App\Models\Channel;
use App\Models\Reseller;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['store.reseller', 'store.channel'])
                ->withSum('items as total_qty', 'qty')
                ->withSum('items as total_revenue', 'revenue')
                ->withSum('items as total_bill', 'bill_amount')
                ->withSum('items as total_margin', 'margin'))
            ->defaultSort('order_date', 'desc')
            ->columns([
                TextColumn::make('order_no')
                    ->label('No. Pesanan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('order_date')
                    ->label('Tanggal')
                    ->date()
                    ->sortable(),
                TextColumn::make('store.name')
                    ->label('Toko')
                    ->searchable(),
                TextColumn::make('store.reseller.name')
                    ->label('Reseller')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (SalesOrderStatus $state): string => SalesOrderInfolist::statusColor($state)),
                TextColumn::make('total_qty')
                    ->label('Qty')
                    ->numeric(),
                TextColumn::make('total_revenue')
                    ->label('Pendapatan')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('total_bill')
                    ->label('Tagihan')
                    ->money('IDR', decimalPlaces: 0)
                    ->summarize(Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                TextColumn::make('total_margin')
                    ->label('Laba Toko')
                    ->money('IDR', decimalPlaces: 0)
                    ->toggleable(),
                TextColumn::make('buyer_name')
                    ->label('Pembeli')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(SalesOrderStatus::class)
                    ->multiple(),
                SelectFilter::make('store')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('reseller')
                    ->label('Reseller')
                    ->options(fn () => Reseller::query()->orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'],
                        fn (Builder $query, $resellerId) => $query->whereHas('store', fn (Builder $store) => $store->where('reseller_id', $resellerId)),
                    )),
                SelectFilter::make('channel')
                    ->label('Channel')
                    ->options(fn () => Channel::query()->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'],
                        fn (Builder $query, $channelId) => $query->whereHas('store', fn (Builder $store) => $store->where('channel_id', $channelId)),
                    )),
                Filter::make('order_date')
                    ->schema([
                        DatePicker::make('from')->label('Dari'),
                        DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $query, $date) => $query->whereDate('order_date', '>=', $date))
                        ->when($data['until'], fn (Builder $query, $date) => $query->whereDate('order_date', '<=', $date))),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ]);
    }
}
