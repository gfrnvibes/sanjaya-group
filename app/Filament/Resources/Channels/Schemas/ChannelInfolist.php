<?php

namespace App\Filament\Resources\Channels\Schemas;

use App\Models\Channel;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ChannelInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Channel')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')
                            ->label('Kode'),
                        TextEntry::make('name')
                            ->label('Nama'),
                        TextEntry::make('kind')
                            ->label('Jenis')
                            ->badge(),
                        TextEntry::make('currentFee')
                            ->label('Fee Admin / Iklan Berlaku')
                            ->state(function (Channel $record): string {
                                $rate = $record->feeRates()->whereDate('effective_from', '<=', today())->latest('effective_from')->first();

                                return $rate
                                    ? number_format((float) $rate->admin_fee_pct * 100, 2, ',', '.').'% / '.number_format((float) $rate->ads_pct * 100, 2, ',', '.').'%'
                                    : '-';
                            }),
                        IconEntry::make('is_active')
                            ->label('Aktif')
                            ->boolean(),
                    ]),
            ]);
    }
}
