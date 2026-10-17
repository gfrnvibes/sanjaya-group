<?php

namespace App\Filament\Resources\Owners\Schemas;

use App\Models\Owner;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OwnerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Pemilik')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')
                            ->label('Kode'),
                        TextEntry::make('name')
                            ->label('Nama'),
                        TextEntry::make('phone')
                            ->label('Telepon')
                            ->placeholder('-'),
                        TextEntry::make('currentTier')
                            ->label('Tier Berlaku')
                            ->state(fn (Owner $record): ?string => $record->tierHistories()
                                ->with('priceTier')
                                ->whereDate('effective_from', '<=', today())
                                ->latest('effective_from')
                                ->first()?->priceTier?->name)
                            ->placeholder('Tanpa tier')
                            ->badge(),
                        TextEntry::make('resellers_count')
                            ->label('Jumlah Reseller')
                            ->state(fn (Owner $record): int => $record->resellers()->count()),
                        IconEntry::make('is_active')
                            ->label('Aktif')
                            ->boolean(),
                        TextEntry::make('deleted_at')
                            ->label('Dihapus')
                            ->dateTime()
                            ->visible(fn (Owner $record): bool => $record->trashed()),
                    ]),
            ]);
    }
}
