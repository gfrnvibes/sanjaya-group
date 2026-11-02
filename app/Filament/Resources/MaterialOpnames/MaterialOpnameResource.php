<?php

namespace App\Filament\Resources\MaterialOpnames;

use App\Enums\StockOpnameStatus;
use App\Filament\Resources\MaterialOpnames\Pages\CreateMaterialOpname;
use App\Filament\Resources\MaterialOpnames\Pages\EditMaterialOpname;
use App\Filament\Resources\MaterialOpnames\Pages\ListMaterialOpnames;
use App\Filament\Resources\MaterialOpnames\Pages\ViewMaterialOpname;
use App\Models\Material;
use App\Models\MaterialOpname;
use App\Models\Warehouse;
use App\Services\StockLedger;
use BackedEnum;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as InfolistColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * OPNAME BAHAN BAKU per gudang bahan (satuan dasar cm/pcs). Selisih -> kartu bahan & jurnal
 * (opname rutin: selisih persediaan; opname awal: saldo awal).
 */
class MaterialOpnameResource extends Resource
{
    protected static ?string $model = MaterialOpname::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Stok';

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'Opname Bahan';

    protected static ?string $pluralModelLabel = 'Opname Bahan Baku';

    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record) && $record->status === StockOpnameStatus::Draft;
    }

    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && $record->status === StockOpnameStatus::Draft;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Data Opname')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('warehouse_id')
                        ->label('Gudang Bahan')
                        ->relationship('warehouse', 'name', fn ($query) => $query->forMaterials())
                        ->default(fn () => Warehouse::defaultForMaterials()?->id)
                        ->required()
                        ->live(),
                    DateTimePicker::make('counted_at')
                        ->label('Waktu Hitung')
                        ->default(now())
                        ->required(),
                    Toggle::make('is_opening')
                        ->label('Opname awal (stok bahan awal belum pernah dicatat lengkap)')
                        ->helperText('Selisihnya dijurnal ke Saldo Awal, bukan ke laba rugi. Centang hanya untuk opname pertama.')
                        ->columnSpanFull(),
                    Textarea::make('note')
                        ->label('Catatan')
                        ->columnSpanFull(),
                ]),
            Section::make('Hasil Hitung')
                ->description('Qty dalam satuan dasar bahan (cm / pcs). Stok sistem dihitung pada waktu hitung; selisih (fisik - sistem) dicatat ke kartu bahan.')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('items')
                        ->hiddenLabel()
                        ->relationship()
                        ->table([
                            TableColumn::make('Bahan'),
                            TableColumn::make('Stok Sistem'),
                            TableColumn::make('Stok Fisik'),
                        ])
                        ->schema([
                            Select::make('material_id')
                                ->options(fn () => Material::query()->orderBy('code')->pluck('code', 'id'))
                                ->searchable()
                                ->required()
                                ->distinct()
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                                    if ($state && $get('../../warehouse_id')) {
                                        $set('system_qty', (float) app(StockLedger::class)->materialQty((int) $state, (int) $get('../../warehouse_id')));
                                    }
                                }),
                            TextInput::make('system_qty')
                                ->numeric()
                                ->default(0)
                                ->readOnly()
                                ->required(),
                            TextInput::make('counted_qty')
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                        ])
                        ->defaultItems(0)
                        ->addActionLabel('Tambah bahan'),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Data Opname')
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('warehouse.name')->label('Gudang'),
                    TextEntry::make('counted_at')->label('Waktu Hitung')->dateTime(),
                    TextEntry::make('status')->label('Status')->badge()
                        ->color(fn ($state): string => $state === StockOpnameStatus::Posted ? 'success' : 'warning'),
                    TextEntry::make('is_opening')->label('Jenis')
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Opname awal (selisih -> Saldo Awal)' : 'Opname rutin (selisih -> laba rugi)'),
                    TextEntry::make('note')->label('Catatan')->placeholder('-')->columnSpanFull(),
                ]),
            Section::make('Hasil Hitung')
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->table([
                            InfolistColumn::make('Bahan'),
                            InfolistColumn::make('Stok Sistem'),
                            InfolistColumn::make('Stok Fisik'),
                            InfolistColumn::make('Selisih'),
                        ])
                        ->schema([
                            TextEntry::make('material.code'),
                            TextEntry::make('system_qty')->numeric(decimalPlaces: 1),
                            TextEntry::make('counted_qty')->numeric(decimalPlaces: 1),
                            TextEntry::make('diff')->numeric(decimalPlaces: 1)->badge()
                                ->color(fn ($state): string => (float) $state == 0 ? 'gray' : ((float) $state > 0 ? 'success' : 'danger')),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('warehouse')->withCount('items'))
            ->defaultSort('counted_at', 'desc')
            ->columns([
                TextColumn::make('counted_at')->label('Waktu Hitung')->dateTime()->sortable(),
                TextColumn::make('warehouse.name')->label('Gudang'),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (StockOpnameStatus $state): string => $state === StockOpnameStatus::Posted ? 'success' : 'warning'),
                IconColumn::make('is_opening')->label('Opname awal')->boolean(),
                TextColumn::make('items_count')->label('Bahan Dihitung'),
                TextColumn::make('posted_at')->label('Diposting')->dateTime()->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(StockOpnameStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()->authorizeIndividualRecords(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaterialOpnames::route('/'),
            'create' => CreateMaterialOpname::route('/create'),
            'view' => ViewMaterialOpname::route('/{record}'),
            'edit' => EditMaterialOpname::route('/{record}/edit'),
        ];
    }
}
