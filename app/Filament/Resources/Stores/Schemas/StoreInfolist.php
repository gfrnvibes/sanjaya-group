<?php

namespace App\Filament\Resources\Stores\Schemas;

use App\Models\Store;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StoreInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Toko')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name')
                            ->label('Nama Toko'),
                        TextEntry::make('reseller.name')
                            ->label('Reseller'),
                        TextEntry::make('reseller.owner.name')
                            ->label('Pemilik'),
                        TextEntry::make('channel.name')
                            ->label('Channel')
                            ->badge(),
                        TextEntry::make('external_ref')
                            ->label('ID Eksternal')
                            ->placeholder('-'),
                        IconEntry::make('is_active')
                            ->label('Aktif')
                            ->boolean(),
                        TextEntry::make('deleted_at')
                            ->label('Dihapus')
                            ->dateTime()
                            ->visible(fn (Store $record): bool => $record->trashed()),
                    ]),
            ]);
    }
}
