<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Filament\Actions\SalesOrderImportActions;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Services\SalesOrderSpreadsheet;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListSalesOrders extends ListRecords
{
    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                SalesOrderImportActions::templateAction(),
                SalesOrderImportActions::importAction(),
            ])
                ->label('Import')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->button()
                ->color('gray'),
            ActionGroup::make(collect(SalesOrderSpreadsheet::FORMATS)
                ->map(fn (string $label, string $format): Action => Action::make('export_'.$format)
                    ->label($label)
                    ->action(fn (SalesOrderSpreadsheet $spreadsheet): BinaryFileResponse => response()
                        ->download(
                            $spreadsheet->export($this->getFilteredSortedTableQuery(), $format),
                            $spreadsheet->fileName('pesanan', $format),
                        )
                        ->deleteFileAfterSend()))
                ->values()
                ->all())
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->tooltip('Export pesanan sesuai filter tabel')
                ->button()
                ->color('gray'),
            CreateAction::make(),
        ];
    }
}
