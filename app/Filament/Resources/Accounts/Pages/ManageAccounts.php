<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAccounts extends ManageRecords
{
    protected static string $resource = AccountResource::class;

    public function getSubheading(): ?string
    {
        return 'Akun sistem dipakai jurnal otomatis (boleh ganti kode & nama). Kategori biaya baru otomatis dibuatkan akun bebannya; rekening baru otomatis dibuatkan akun kas.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
