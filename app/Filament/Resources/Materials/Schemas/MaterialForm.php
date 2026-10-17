<?php

namespace App\Filament\Resources\Materials\Schemas;

use App\Enums\BaseUom;
use App\Models\Color;
use App\Models\MaterialType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class MaterialForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Bahan')
                    ->description('Satu bahan = satu jenis bahan dalam satu warna, mis. "DRILL - HITAM".')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('material_type_id')
                            ->label('Jenis Bahan')
                            ->relationship('materialType', 'name')
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::suggestCode($get, $set)),
                        Select::make('color_id')
                            ->label('Warna')
                            ->relationship('color', 'name')
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::suggestCode($get, $set)),
                        TextInput::make('code')
                            ->label('Kode Bahan')
                            ->required()
                            ->maxLength(60)
                            ->unique(ignoreRecord: true),
                        Select::make('base_uom')
                            ->label('Satuan Dasar')
                            ->options(BaseUom::class)
                            ->default(BaseUom::Cm)
                            ->required(),
                        TextInput::make('pack_uom')
                            ->label('Satuan Beli')
                            ->default('roll')
                            ->required()
                            ->maxLength(10),
                        TextInput::make('qty_per_pack')
                            ->label('Isi per Satuan Beli')
                            ->helperText('Mis. 1 roll drill = 3000 cm.')
                            ->numeric()
                            ->minValue(0.001)
                            ->required(),
                        TextInput::make('min_stock')
                            ->label('Stok Minimum (satuan dasar)')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }

    private static function suggestCode(Get $get, Set $set): void
    {
        $type = MaterialType::query()->find($get('material_type_id'))?->code;
        $color = Color::query()->find($get('color_id'))?->name;

        if ($type) {
            $set('code', $color ? strtoupper("{$type} - {$color}") : strtoupper($type));
        }
    }
}
