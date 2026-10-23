<?php

namespace App\Filament\Resources\LaborTypes;

use App\Enums\WageType;
use App\Filament\Resources\LaborTypes\Pages\ManageLaborTypes;
use App\Models\LaborType;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class LaborTypeResource extends Resource
{
    protected static ?string $model = LaborType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Bahan & HPP';

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'Jenis Ongkos Kerja';

    protected static ?string $pluralModelLabel = 'Jenis Ongkos Kerja';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kode')
                    ->helperText('POTONG, JAHIT, BORDIR, OBRAS, ...')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->maxLength(60)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set, string $operation): void {
                        if ($operation === 'create' && blank($get('wage_type'))) {
                            $set('wage_type', LaborType::guessWageType((string) $get('code'), (string) $state)?->value);
                        }
                    }),
                Select::make('wage_type')
                    ->label('Dibayar sebagai')
                    ->options(WageType::class)
                    ->placeholder('Tidak (dibayar lewat Biaya Operasional)')
                    ->helperText('Upah Jahit = dibayar ke penjahit, Upah Potong = ke tukang potong, lewat Pembayaran Upah per SPK. Kosong = obras, bordir, dll. dibayar lewat Biaya Operasional. Kode bebas.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('bomItems'))
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable(),
                TextColumn::make('wage_type')
                    ->label('Dibayar sebagai')
                    ->badge()
                    ->placeholder('Biaya Operasional'),
                TextColumn::make('bom_items_count')
                    ->label('Dipakai di Resep'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLaborTypes::route('/'),
        ];
    }
}
