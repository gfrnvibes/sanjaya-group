<?php

namespace App\Filament\Resources\MaterialTypes;

use App\Enums\MaterialKind;
use App\Filament\Resources\MaterialTypes\Pages\ManageMaterialTypes;
use App\Models\MaterialType;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class MaterialTypeResource extends Resource
{
    protected static ?string $model = MaterialType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Bahan & HPP';

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'Jenis Bahan';

    protected static ?string $pluralModelLabel = 'Jenis Bahan';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kode')
                    ->helperText('DRILL, LINEN, JARING, KANCING, ...')
                    ->required()
                    ->maxLength(30)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->maxLength(80),
                Select::make('kind')
                    ->label('Golongan')
                    ->options(MaterialKind::class)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('materials'))
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable(),
                TextColumn::make('kind')
                    ->label('Golongan')
                    ->badge(),
                TextColumn::make('materials_count')
                    ->label('Bahan (warna)'),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Golongan')
                    ->options(MaterialKind::class),
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
            'index' => ManageMaterialTypes::route('/'),
        ];
    }
}
