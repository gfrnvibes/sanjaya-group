<?php

namespace App\Filament\Resources\Boms\Schemas;

use App\Enums\BomColorMode;
use App\Enums\BomLineType;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class BomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Resep')
                    ->description('Resep berlaku untuk model (dan ukuran tertentu bila diisi) mulai tanggal berlaku. Ubah resep = buat versi baru.')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('model_id')
                            ->label('Model')
                            ->relationship('model', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('size_id')
                            ->label('Ukuran')
                            ->relationship('size', 'code', fn ($query) => $query->orderBy('sort_order'))
                            ->placeholder('Semua ukuran')
                            ->preload(),
                        DatePicker::make('effective_from')
                            ->label('Berlaku Mulai')
                            ->default(today())
                            ->required(),
                        TextInput::make('note')
                            ->label('Catatan')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
                Section::make('Komponen')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->relationship()
                            ->columns(4)
                            ->schema([
                                Select::make('line_type')
                                    ->label('Jenis Baris')
                                    ->options(BomLineType::class)
                                    ->default(BomLineType::Material)
                                    ->required()
                                    ->live(),
                                Select::make('material_type_id')
                                    ->label('Jenis Bahan')
                                    ->relationship('materialType', 'name')
                                    ->preload()
                                    ->required()
                                    ->visible(fn (Get $get): bool => self::isMaterial($get)),
                                Select::make('color_mode')
                                    ->label('Warna Bahan')
                                    ->options(BomColorMode::class)
                                    ->default(BomColorMode::FollowVariant)
                                    ->required()
                                    ->live()
                                    ->visible(fn (Get $get): bool => self::isMaterial($get)),
                                Select::make('material_id')
                                    ->label('Bahan Tetap')
                                    ->relationship('material', 'code', fn ($query, Get $get) => $query->where('material_type_id', $get('material_type_id')))
                                    ->required()
                                    ->visible(fn (Get $get): bool => self::isMaterial($get) && self::colorMode($get) === BomColorMode::Fixed),
                                Select::make('labor_type_id')
                                    ->label('Jenis Ongkos')
                                    ->relationship('laborType', 'name')
                                    ->preload()
                                    ->required()
                                    ->visible(fn (Get $get): bool => ! self::isMaterial($get)),
                                TextInput::make('labor_rate')
                                    ->label('Ongkos / pcs')
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('Rp')
                                    ->required()
                                    ->visible(fn (Get $get): bool => ! self::isMaterial($get)),
                                TextInput::make('qty_per_unit')
                                    ->label(fn (Get $get): string => self::isMaterial($get) ? 'Pemakaian / produk (cm / pcs)' : 'Pengali')
                                    ->numeric()
                                    ->minValue(0.0001)
                                    ->default(1)
                                    ->required(),
                            ])
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::cleanLine($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::cleanLine($data))
                            ->itemLabel(fn (array $state): ?string => ($state['line_type'] ?? null) instanceof BomLineType
                                ? $state['line_type']->getLabel()
                                : BomLineType::tryFrom((string) ($state['line_type'] ?? ''))?->getLabel())
                            ->minItems(1)
                            ->defaultItems(1)
                            ->collapsible()
                            ->addActionLabel('Tambah komponen'),
                    ]),
            ]);
    }

    private static function isMaterial(Get $get): bool
    {
        $type = $get('line_type');

        return ($type instanceof BomLineType ? $type : BomLineType::tryFrom((string) $type)) !== BomLineType::Labor;
    }

    private static function colorMode(Get $get): ?BomColorMode
    {
        $mode = $get('color_mode');

        return $mode instanceof BomColorMode ? $mode : BomColorMode::tryFrom((string) $mode);
    }

    /**
     * Kosongkan kolom yang tidak relevan agar lolos CHECK bom_items_line_check.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function cleanLine(array $data): array
    {
        $type = $data['line_type'] instanceof BomLineType ? $data['line_type'] : BomLineType::from($data['line_type']);

        if ($type === BomLineType::Labor) {
            return [...$data, 'material_type_id' => null, 'color_mode' => null, 'material_id' => null];
        }

        $mode = $data['color_mode'] instanceof BomColorMode ? $data['color_mode'] : BomColorMode::from($data['color_mode']);

        return [
            ...$data,
            'labor_type_id' => null,
            'labor_rate' => null,
            'material_id' => $mode === BomColorMode::Fixed ? ($data['material_id'] ?? null) : null,
        ];
    }
}
