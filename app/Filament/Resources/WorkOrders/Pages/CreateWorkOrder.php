<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Services\WorkOrderService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWorkOrder extends CreateRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        try {
            return app(WorkOrderService::class)->create($data, array_values($items));
        } catch (BusinessRuleException $exception) {
            Notification::make()->title('SPK tidak bisa disimpan')->body($exception->getMessage())->danger()->send();

            $this->halt();
        }
    }

    /**
     * Varian yang upah jahit / upah potongnya Rp0 (resep belum punya ongkos yang dibayar sebagai upah).
     */
    protected function afterCreate(): void
    {
        $items = $this->getRecord()->items()->with('variant')->get();
        $warnings = collect([
            'upah jahit' => $items->filter(fn ($item): bool => (float) $item->sewing_cost_snapshot <= 0),
            'upah potong' => $items->filter(fn ($item): bool => (float) $item->cutting_cost_snapshot <= 0),
        ])->filter->isNotEmpty()->map(fn ($zero, string $label): string => ucfirst($label).' Rp0 untuk: '.e($zero->pluck('variant.sku')->implode(', ')));

        if ($warnings->isNotEmpty()) {
            Notification::make()
                ->title('SPK tersimpan, cek ongkos upah di resep')
                ->body($warnings->implode('<br>').'<br>Resep model ini belum punya ongkos yang dibayar sebagai upah. Upah tetap bisa diisi manual saat bayar.')
                ->warning()
                ->persistent()
                ->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
