<?php

namespace App\Filament\Resources\Channels\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FeeRatesRelationManager extends RelationManager
{
    protected static string $relationship = 'feeRates';

    protected static ?string $title = 'Riwayat Fee Channel';

    protected static ?string $modelLabel = 'Fee';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('effective_from')
                    ->label('Berlaku Mulai')
                    ->default(today())
                    ->required(),
                TextInput::make('admin_fee_pct')
                    ->label('Potongan Admin')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%')
                    ->default(0)
                    ->required()
                    ->formatStateUsing(fn ($state): ?float => $state === null ? null : (float) $state * 100)
                    ->dehydrateStateUsing(fn ($state): float => (float) $state / 100),
                TextInput::make('ads_pct')
                    ->label('Iklan (estimasi)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%')
                    ->default(0)
                    ->required()
                    ->formatStateUsing(fn ($state): ?float => $state === null ? null : (float) $state * 100)
                    ->dehydrateStateUsing(fn ($state): float => (float) $state / 100),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('effective_from')
                    ->label('Berlaku Mulai')
                    ->date()
                    ->sortable(),
                TextColumn::make('admin_fee_pct')
                    ->label('Potongan Admin')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state * 100, 2, ',', '.').'%'),
                TextColumn::make('ads_pct')
                    ->label('Iklan')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state * 100, 2, ',', '.').'%'),
            ])
            ->headerActions([
                CreateAction::make(),
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
}
