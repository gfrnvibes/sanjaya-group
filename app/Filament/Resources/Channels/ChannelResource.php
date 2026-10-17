<?php

namespace App\Filament\Resources\Channels;

use App\Filament\Resources\Channels\Pages\CreateChannel;
use App\Filament\Resources\Channels\Pages\EditChannel;
use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Filament\Resources\Channels\Pages\ViewChannel;
use App\Filament\Resources\Channels\RelationManagers\FeeRatesRelationManager;
use App\Filament\Resources\Channels\RelationManagers\StoresRelationManager;
use App\Filament\Resources\Channels\Schemas\ChannelForm;
use App\Filament\Resources\Channels\Schemas\ChannelInfolist;
use App\Filament\Resources\Channels\Tables\ChannelsTable;
use App\Models\Channel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ChannelResource extends Resource
{
    protected static ?string $model = Channel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Reseller & Toko';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'Channel';

    protected static ?string $pluralModelLabel = 'Channel';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ChannelForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ChannelInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChannelsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            FeeRatesRelationManager::class,
            StoresRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChannels::route('/'),
            'create' => CreateChannel::route('/create'),
            'view' => ViewChannel::route('/{record}'),
            'edit' => EditChannel::route('/{record}/edit'),
        ];
    }
}
