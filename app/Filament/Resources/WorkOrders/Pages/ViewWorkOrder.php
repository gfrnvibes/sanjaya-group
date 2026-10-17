<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Enums\WorkOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewWorkOrder extends ViewRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startSewing')
                ->label('Mulai Jahit')
                ->icon(Heroicon::OutlinedPlayCircle)
                ->color('info')
                ->visible(fn (WorkOrder $record): bool => $record->status === WorkOrderStatus::Cutting)
                ->requiresConfirmation()
                ->action(function (WorkOrder $record, WorkOrderService $service): void {
                    $service->startSewing($record);

                    Notification::make()->title('SPK masuk tahap jahit')->success()->send();
                }),
            Action::make('complete')
                ->label('Selesai & Terima Barang')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (WorkOrder $record): bool => in_array($record->status, [WorkOrderStatus::Cutting, WorkOrderStatus::Sewing], true))
                ->modalWidth('3xl')
                ->modalDescription('Isi qty jadi & reject per varian. Qty jadi masuk ke stok barang jadi.')
                ->fillForm(fn (WorkOrder $record): array => [
                    'finished_date' => today(),
                    'results' => $record->items()->with('variant')->orderBy('id')->get()->map(fn ($item): array => [
                        'sku' => $item->variant->sku,
                        'qty_cut' => $item->qty_cut,
                        'qty_good' => $item->qty_cut,
                        'qty_reject' => 0,
                    ])->all(),
                ])
                ->schema([
                    DatePicker::make('finished_date')
                        ->label('Tanggal Selesai')
                        ->required(),
                    Repeater::make('results')
                        ->label('Hasil')
                        ->table([
                            TableColumn::make('SKU'),
                            TableColumn::make('Potong'),
                            TableColumn::make('Jadi'),
                            TableColumn::make('Reject'),
                        ])
                        ->schema([
                            TextInput::make('sku')->readOnly(),
                            TextInput::make('qty_cut')->readOnly(),
                            TextInput::make('qty_good')->integer()->minValue(0)->required(),
                            TextInput::make('qty_reject')->integer()->minValue(0)->required(),
                        ])
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false),
                ])
                ->action(function (WorkOrder $record, array $data, WorkOrderService $service): void {
                    $rows = array_values($data['results']);
                    $results = $record->items()->orderBy('id')->pluck('id')
                        ->mapWithKeys(fn (int $itemId, int $index): array => [$itemId => [
                            'qty_good' => (int) $rows[$index]['qty_good'],
                            'qty_reject' => (int) $rows[$index]['qty_reject'],
                        ]])
                        ->all();

                    try {
                        $service->complete($record, $results, $data['finished_date']);
                    } catch (BusinessRuleException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('SPK selesai, barang jadi masuk stok')->success()->send();
                }),
            Action::make('cancel')
                ->label('Batalkan SPK')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (WorkOrder $record): bool => in_array($record->status, [WorkOrderStatus::Cutting, WorkOrderStatus::Sewing], true))
                ->requiresConfirmation()
                ->modalDescription('Bahan yang sudah dipotong tidak dikembalikan otomatis. Gunakan Koreksi bahan bila ada sisa yang kembali ke gudang.')
                ->action(function (WorkOrder $record, WorkOrderService $service): void {
                    $service->cancel($record);

                    Notification::make()->title('SPK dibatalkan')->warning()->send();
                }),
            EditAction::make(),
        ];
    }
}
