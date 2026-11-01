<?php

namespace App\Filament\Resources\ResellerInvoices\Tables;

use App\Enums\ResellerInvoiceStatus;
use App\Filament\Resources\ResellerInvoices\Schemas\ResellerInvoiceInfolist;
use App\Filament\Tables\Filters\DateRangeFilter;
use App\Models\Owner;
use App\Models\ResellerInvoice;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ResellerInvoicesTable
{
    public const DATE_FIELDS = [
        'period' => 'Periode tagihan',
        'issued_at' => 'Tanggal terbit',
        'due_date' => 'Jatuh tempo',
    ];

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
                // Periode: ikut tercetak di Cetak / PDF & export, jadi cetak tagihan cukup pilih filter ini.
                DateRangeFilter::make(
                    'period',
                    'Periode',
                    fn (Builder $query, ?string $from, ?string $until, array $data): Builder => match ($data['date_field'] ?? 'period') {
                        'issued_at', 'due_date' => $query
                            ->when($from, fn (Builder $query) => $query->whereDate($data['date_field'], '>=', $from))
                            ->when($until, fn (Builder $query) => $query->whereDate($data['date_field'], '<=', $until)),
                        // periode tagihan yang beririsan dengan rentang
                        default => $query
                            ->when($from, fn (Builder $query) => $query->whereDate('period_end', '>=', $from))
                            ->when($until, fn (Builder $query) => $query->whereDate('period_start', '<=', $until)),
                    },
                    [
                        Select::make('date_field')
                            ->label('Berdasarkan')
                            ->options(self::DATE_FIELDS)
                            ->default('period')
                            ->selectablePlaceholder(false)
                            ->columnSpanFull(),
                    ],
                )->columnSpanFull(),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ResellerInvoiceStatus::class)
                    ->multiple(),
                SelectFilter::make('reseller')
                    ->label('Reseller')
                    ->relationship('reseller', 'name')
                    ->searchable()
                    ->preload()
                    ->multiple(),
                SelectFilter::make('owner')
                    ->label('Pemilik')
                    ->options(fn (): array => Owner::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, $owner) => $query->whereHas('reseller', fn (Builder $query) => $query->where('owner_id', $owner)),
                    )),
                TernaryFilter::make('is_auto')
                    ->label('Dibuat')
                    ->placeholder('Otomatis & manual')
                    ->trueLabel('Otomatis')
                    ->falseLabel('Manual'),
                Filter::make('overdue')
                    ->label('Lewat jatuh tempo (terbit)')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', ResellerInvoiceStatus::Issued)
                        ->whereDate('due_date', '<', today())),
                Filter::make('has_items')
                    ->label('Hanya yang ada penjualannya')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->has('items')),
            ])
            ->filtersFormColumns(2)
            ->filtersFormWidth(Width::ThreeExtraLarge)
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
