<?php

namespace App\Filament\Resources\Owners\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TierHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'tierHistories';

    protected static ?string $title = 'Riwayat Tier';

    protected static ?string $modelLabel = 'Tier';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('price_tier_id')
                    ->label('Tier')
                    ->relationship('priceTier', 'name')
                    ->preload()
                    ->required(),
                DatePicker::make('effective_from')
                    ->label('Berlaku Mulai')
                    ->default(today())
                    ->required(),
                TextInput::make('note')
                    ->label('Catatan')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('priceTier.name')
                    ->label('Tier')
                    ->badge(),
                TextColumn::make('priceTier.adjustment_pct')
                    ->label('Penyesuaian')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state * 100, 2, ',', '.').'%'),
                TextColumn::make('effective_from')
                    ->label('Berlaku Mulai')
                    ->date()
                    ->sortable(),
                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('-'),
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
