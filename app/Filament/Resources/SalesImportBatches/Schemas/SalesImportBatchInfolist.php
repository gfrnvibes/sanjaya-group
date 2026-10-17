<?php

namespace App\Filament\Resources\SalesImportBatches\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SalesImportBatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Batch Impor')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('file_name')
                            ->label('File')
                            ->placeholder('-'),
                        TextEntry::make('source')
                            ->label('Sumber')
                            ->badge(),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge(),
                        TextEntry::make('importer.name')
                            ->label('Diimpor Oleh')
                            ->placeholder('-'),
                        TextEntry::make('row_count')
                            ->label('Baris'),
                        TextEntry::make('error_count')
                            ->label('Error')
                            ->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'gray'),
                        TextEntry::make('created_at')
                            ->label('Waktu')
                            ->dateTime(),
                    ]),
            ]);
    }
}
