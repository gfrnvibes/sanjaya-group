<?php

namespace App\Filament\Resources\Boms\Tables;

use App\Models\Bom;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['model', 'size'])->withCount('items'))
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('model.name')
                    ->label('Model')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('size.code')
                    ->label('Ukuran')
                    ->placeholder('Semua'),
                TextColumn::make('effective_from')
                    ->label('Berlaku Mulai')
                    ->date()
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label('Komponen'),
                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('-')
                    ->limit(40),
            ])
            ->filters([
                SelectFilter::make('model')
                    ->label('Model')
                    ->relationship('model', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ReplicateAction::make()
                    ->label('Versi Baru')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->modalDescription('Salin resep beserta komponennya sebagai versi baru dengan tanggal berlaku baru.')
                    ->schema([
                        DatePicker::make('effective_from')
                            ->label('Berlaku Mulai')
                            ->default(today())
                            ->required(),
                    ])
                    ->excludeAttributes(['items_count'])
                    ->after(function (Bom $record, Bom $replica): void {
                        foreach ($record->items as $item) {
                            $replica->items()->create($item->replicate(['bom_id'])->toArray());
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
