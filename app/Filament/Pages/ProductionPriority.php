<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * DASHBOARD Excel: terlaris & prioritas produksi (fn_production_priority).
 */
class ProductionPriority extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFire;

    protected static string|UnitEnum|null $navigationGroup = 'Produksi';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Prioritas Produksi';

    protected static ?string $title = 'Terlaris & Prioritas Produksi';

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (array $filters, ?string $sortColumn, ?string $sortDirection, ?string $search): array {
                $params = $filters['params'] ?? [];
                $rows = collect(DB::select(
                    'select * from fn_production_priority(?, ?, ?, ?, ?::date)',
                    [
                        (int) ($params['days'] ?? Setting::valueOf('analysis_days', 30)),
                        (int) ($params['target_days'] ?? Setting::valueOf('target_stock_days', 30)),
                        (int) ($params['lead_days'] ?? Setting::valueOf('lead_time_days', 7)),
                        max(1, (int) ($params['batch'] ?? Setting::valueOf('production_batch', 1))),
                        $params['ref_date'] ?? today()->toDateString(),
                    ],
                ))->map(fn (object $row): array => (array) $row);

                if (filled($priority = $filters['priority']['value'] ?? null)) {
                    $rows = $rows->where('priority', $priority);
                }

                if (filled($search)) {
                    $rows = $rows->filter(fn (array $row): bool => str_contains(strtolower($row['sku'].' '.$row['model']), strtolower($search)));
                }

                $rows = $sortColumn
                    ? $rows->sortBy($sortColumn, SORT_NATURAL, $sortDirection === 'desc')
                    : $rows->sortBy([['priority', 'asc'], ['sold', 'desc']]);

                return $rows->keyBy('variant_id')->all();
            })
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('model')
                    ->label('Model')
                    ->sortable(),
                TextColumn::make('sold')
                    ->label('Terjual')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('per_day')
                    ->label('Rata-rata / hari')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('stock')
                    ->label('Stok')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('days_of_stock')
                    ->label('Cukup (hari)')
                    ->numeric(decimalPlaces: 1)
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('suggested_qty')
                    ->label('Saran Produksi')
                    ->numeric()
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('priority')
                    ->label('Prioritas')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        '1 HABIS' => 'danger',
                        '2 SEGERA' => 'warning',
                        '3 PERLU' => 'info',
                        'AMAN' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->filters([
                Filter::make('params')
                    ->schema([
                        TextInput::make('days')
                            ->label('Hari analisis')
                            ->integer()
                            ->minValue(1)
                            ->default(fn () => Setting::valueOf('analysis_days', 30)),
                        TextInput::make('target_days')
                            ->label('Target stok (hari)')
                            ->integer()
                            ->minValue(1)
                            ->default(fn () => Setting::valueOf('target_stock_days', 30)),
                        TextInput::make('lead_days')
                            ->label('Lead time (hari)')
                            ->integer()
                            ->minValue(0)
                            ->default(fn () => Setting::valueOf('lead_time_days', 7)),
                        TextInput::make('batch')
                            ->label('Kelipatan produksi')
                            ->integer()
                            ->minValue(1)
                            ->default(fn () => Setting::valueOf('production_batch', 1)),
                        DatePicker::make('ref_date')
                            ->label('Tanggal acuan')
                            ->default(today()),
                    ])
                    ->columns(5)
                    ->columnSpanFull(),
                SelectFilter::make('priority')
                    ->label('Prioritas')
                    ->options([
                        '1 HABIS' => 'Habis',
                        '2 SEGERA' => 'Segera',
                        '3 PERLU' => 'Perlu',
                        'AMAN' => 'Aman',
                        '-' => 'Tidak laku',
                    ]),
            ])
            ->filtersFormColumns(5)
            ->recordActions([
                Action::make('openVariant')
                    ->label('Varian')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (array $record): string => ProductVariantResource::getUrl('view', ['record' => $record['variant_id']])),
            ]);
    }
}
