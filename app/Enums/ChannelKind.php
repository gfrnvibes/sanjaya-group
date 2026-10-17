<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ChannelKind: string implements HasLabel
{
    case Marketplace = 'marketplace';
    case Website = 'website';
    case Chat = 'chat';
    case Offline = 'offline';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Marketplace => 'Marketplace',
            self::Website => 'Website',
            self::Chat => 'Chat (WA)',
            self::Offline => 'Offline',
            self::Other => 'Lainnya',
        };
    }
}
