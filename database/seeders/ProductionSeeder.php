<?php

namespace Database\Seeders;

use App\Models\CashAccount;
use App\Models\ProductVariant;
use App\Models\Tailor;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use Illuminate\Database\Seeder;

/**
 * SPK (selesai, sedang jahit, baru potong) dan pembayaran upah jahit.
 */
class ProductionSeeder extends Seeder
{
    public function run(WorkOrderService $workOrders): void
    {
        $main = Warehouse::query()->where('code', 'UTAMA')->value('id');
        $joko = Tailor::query()->where('name', 'Pak Joko')->value('id');
        $sri = Tailor::query()->where('name', 'Bu Sri')->value('id');
        $cash = CashAccount::query()->where('name', 'Kas Tunai')->value('id');
        $variant = fn (string $sku): int => ProductVariant::query()->where('sku', $sku)->value('id');

        $orders = [
            // [code, tailor, cut_date, items [sku => qty_cut], selesai? [tanggal, reject per sku], status akhir]
            ['SPK-260803-01', $joko, '2026-08-03', ['BC1HM' => 30, 'BC1HL' => 24, 'BC1HXL' => 12], ['2026-08-14', ['BC1HM' => 1]]],
            ['SPK-260810-01', $sri, '2026-08-10', ['AP1H' => 30, 'AP1N' => 24], ['2026-08-18', []]],
            ['SPK-260916-01', $joko, '2026-09-16', ['BC1PM' => 72, 'BC1PL' => 24, 'BC1NM' => 18], ['2026-09-26', ['BC1PL' => 2]]],
            ['SPK-261005-01', $sri, '2026-10-05', ['BC1HS' => 18, 'BC1HM' => 24], 'sewing'],
            ['SPK-261014-01', $joko, '2026-10-14', ['BC1NL' => 12, 'BC1NXL' => 6], 'cutting'],
        ];

        foreach ($orders as [$code, $tailorId, $cutDate, $items, $result]) {
            $workOrder = $workOrders->create(
                ['code' => $code, 'tailor_id' => $tailorId, 'warehouse_id' => $main, 'cut_date' => $cutDate],
                collect($items)->map(fn (int $qty, string $sku): array => ['variant_id' => $variant($sku), 'qty_cut' => $qty])->values()->all(),
            );

            if ($result === 'cutting') {
                continue;
            }

            $workOrders->startSewing($workOrder);

            if ($result === 'sewing') {
                continue;
            }

            [$finishedDate, $rejects] = $result;
            $results = $workOrder->items()->with('variant')->get()->mapWithKeys(fn ($item): array => [$item->id => [
                'qty_good' => $item->qty_cut - ($rejects[$item->variant->sku] ?? 0),
                'qty_reject' => $rejects[$item->variant->sku] ?? 0,
            ]])->all();

            $workOrders->complete($workOrder->refresh(), $results, $finishedDate);
        }

        // Pembayaran upah: SPK Agustus lunas, SPK September dibayar sebagian.
        foreach ([['SPK-260803-01', '2026-08-15', null], ['SPK-260810-01', '2026-08-19', null], ['SPK-260916-01', '2026-09-27', 1500000]] as [$code, $paidAt, $amount]) {
            $workOrder = WorkOrder::query()->where('code', $code)->firstOrFail();
            $labor = (float) $workOrder->items()->get()->sum(fn ($item): float => $item->qty_good * (float) $item->labor_cost_snapshot);

            $workOrder->tailorPayments()->create([
                'tailor_id' => $workOrder->tailor_id,
                'paid_at' => $paidAt,
                'amount' => $amount ?? $labor,
                'cash_account_id' => $cash,
                'note' => $amount ? 'Pembayaran sebagian' : 'Lunas',
            ]);
        }
    }
}
