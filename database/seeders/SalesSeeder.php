<?php

namespace Database\Seeders;

use App\Enums\ImportBatchStatus;
use App\Enums\SalesImportSource;
use App\Enums\SalesOrderStatus;
use App\Models\ProductVariant;
use App\Models\SalesImportBatch;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\Warehouse;
use App\Services\PricingService;
use App\Services\SalesOrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Pesanan Agustus - Oktober di semua toko (marketplace, WA, offline), batch impor BigSeller,
 * pesanan batal, retur, dan biaya iklan toko.
 */
class SalesSeeder extends Seeder
{
    public function run(SalesOrderService $sales, PricingService $pricing): void
    {
        mt_srand(2026);
        $faker = fake('id_ID');
        $faker->seed(2026);

        $main = Warehouse::query()->where('code', 'UTAMA')->value('id');
        $showroom = Warehouse::query()->where('code', 'SHOWROOM')->value('id');
        $stores = Store::query()->with(['channel', 'reseller'])->get();
        $variants = ProductVariant::query()->get();
        $popular = $variants->whereIn('sku', ['BC1HM', 'BC1HL', 'BC1PM', 'AP1H', 'TC1P'])->values();

        $batch = SalesImportBatch::create([
            'source' => SalesImportSource::BigSeller,
            'file_name' => 'export_bigseller_september_2026.xlsx',
            'status' => ImportBatchStatus::Done,
            'created_at' => '2026-10-01 09:00:00',
        ]);

        $sequence = 0;

        for ($date = Carbon::parse('2026-08-01'); $date->lte(Carbon::parse('2026-10-16')); $date->addDay()) {
            foreach ($stores as $store) {
                // Marketplace: pesanan eceran sering; WA / offline: pesanan grosir seragam resto.
                $chance = match ($store->channel->code) {
                    'SHOPEE' => 75,
                    'TIKTOK' => 50,
                    default => 30,
                };

                if (mt_rand(1, 100) > $chance) {
                    continue;
                }

                $sequence++;
                $isMarketplace = in_array($store->channel->code, ['SHOPEE', 'TIKTOK'], true);
                $lines = [];

                foreach (range(1, mt_rand(1, 3)) as $ignored) {
                    $variant = mt_rand(1, 100) <= 60
                        ? $popular[mt_rand(0, $popular->count() - 1)]
                        : $variants[mt_rand(0, $variants->count() - 1)];
                    $qty = $isMarketplace
                        ? (mt_rand(1, 100) <= 75 ? 1 : mt_rand(2, 4))
                        : mt_rand(3, 10);
                    $unit = $pricing->snapshot($variant->id, $store->reseller->owner_id, $date)['reseller_unit_price'];
                    $markup = mt_rand(130, 165) / 100;

                    $lines[$variant->id] = [
                        'variant_id' => $variant->id,
                        'qty' => ($lines[$variant->id]['qty'] ?? 0) + $qty,
                        'revenue' => round($unit * $markup * $qty, -3),
                        'sku_raw' => $isMarketplace ? 'BS-'.$variant->sku : null,
                    ];
                }

                $revenue = collect($lines)->sum('revenue');
                $fromBatch = $isMarketplace && $date->month === 9 && $store->channel->code === 'SHOPEE';
                $recent = $date->gte(Carbon::parse('2026-10-14'));

                $sales->create([
                    'store_id' => $store->id,
                    'warehouse_id' => $store->channel->code === 'OFFLINE' ? $showroom : $main,
                    'order_no' => $isMarketplace
                        ? $date->format('ymd').strtoupper(substr(md5((string) $sequence), 0, 8))
                        : 'ORD-'.$date->format('ymd').'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
                    'order_date' => $date->toDateString(),
                    'status' => $recent && mt_rand(0, 1) ? SalesOrderStatus::Pending : SalesOrderStatus::Completed,
                    'buyer_name' => $faker->name(),
                    // Sebagian pesanan marketplace punya potongan admin aktual dari export.
                    'platform_fee' => $isMarketplace && ($fromBatch || mt_rand(1, 100) <= 30) ? round($revenue * 0.095, -2) : null,
                    'shipping_fee' => $isMarketplace ? null : (mt_rand(0, 1) ? 15000 : null),
                    'import_batch_id' => $fromBatch ? $batch->id : null,
                ], array_values($lines));
            }
        }

        $batch->update([
            'row_count' => $batch->salesOrders()->withCount('items')->get()->sum('items_count'),
            'error_count' => 2,
        ]);

        $this->seedCancellationsAndReturns($sales, $main);
        $this->seedAdSpends($stores);
    }

    private function seedCancellationsAndReturns(SalesOrderService $sales, int $main): void
    {
        $orders = SalesOrder::query()
            ->where('status', SalesOrderStatus::Completed)
            ->whereHas('store.channel', fn ($query) => $query->whereIn('code', ['SHOPEE', 'TIKTOK']))
            ->orderBy('order_date')
            ->get();

        // 3 pesanan batal (stok kembali).
        foreach ($orders->slice(5, 3) as $order) {
            $sales->cancel($order);
        }

        // Retur: sebagian restock & dikreditkan, satu barang rusak tidak restock, satu retur penuh.
        $returns = [
            [$orders[12], ['restock' => true, 'credit_reseller' => true, 'note' => 'Salah ukuran']],
            [$orders[20], ['restock' => false, 'credit_reseller' => true, 'note' => 'Cacat jahitan, dibuang']],
            [$orders[35], ['restock' => true, 'credit_reseller' => false, 'note' => 'Retur pembeli, tagihan tetap']],
        ];

        foreach ($returns as [$order, $options]) {
            $item = $order->items()->first();

            $sales->registerReturn($item, [
                'returned_at' => $order->order_date->copy()->addDays(6)->toDateString(),
                'qty' => 1,
                'warehouse_id' => $main,
                ...$options,
            ]);
        }

        $fullReturn = $orders[45];

        foreach ($fullReturn->items as $item) {
            $sales->registerReturn($item, [
                'returned_at' => $fullReturn->order_date->copy()->addDays(5)->toDateString(),
                'qty' => $item->qty,
                'restock' => true,
                'warehouse_id' => $main,
                'credit_reseller' => true,
                'note' => 'Pesanan dikembalikan seluruhnya',
            ]);
        }
    }

    private function seedAdSpends($stores): void
    {
        foreach ($stores->filter(fn (Store $store): bool => in_array($store->channel->code, ['SHOPEE', 'TIKTOK'], true)) as $store) {
            foreach (['2026-08', '2026-09'] as $month) {
                foreach ([5, 20] as $day) {
                    $store->adSpends()->create([
                        'spend_date' => "{$month}-{$day}",
                        'amount' => mt_rand(8, 25) * 10000,
                        'note' => 'Top up saldo iklan '.$store->channel->name,
                    ]);
                }
            }
        }
    }
}
