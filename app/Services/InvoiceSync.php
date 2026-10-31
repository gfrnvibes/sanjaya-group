<?php

namespace App\Services;

use App\Enums\InvoiceCycle;
use App\Enums\ResellerInvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Reseller;
use App\Models\ResellerInvoice;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TAGIHAN RESELLER OTOMATIS: setiap baris penjualan / retur reseller yang belum ditagih masuk ke tagihan DRAFT
 * yang periodenya mencakup tanggalnya (draft yang sudah ada dipakai dulu, termasuk draft manual); bila belum ada,
 * draft dibuat sesuai siklus reseller (mingguan/bulanan + hari/tanggal mulai; kosong = pengaturan umum).
 * Pesanan batal sudah dilepas dari draft oleh SalesOrderService; draft otomatis yang kosong dihapus.
 * Tagihan terbit tidak pernah diubah: penjualan susulan untuk periode yang sudah terbit masuk draft baru.
 */
class InvoiceSync
{
    private const SCHEDULED = 'invoice-sync.scheduled';

    public function __construct(private InvoiceService $invoices) {}

    public static function enabled(): bool
    {
        return (bool) (int) Setting::valueOf('invoice_auto', 1);
    }

    public static function defaultCycle(): InvoiceCycle
    {
        return InvoiceCycle::tryFrom((string) Setting::valueOf('invoice_cycle', InvoiceCycle::Monthly->value)) ?? InvoiceCycle::Monthly;
    }

    public static function defaultDay(): int
    {
        return self::defaultCycle()->clampDay((int) Setting::valueOf('invoice_cycle_day', 1));
    }

    /**
     * @return array{0: InvoiceCycle, 1: int}
     */
    public function cycleFor(Reseller $reseller): array
    {
        $cycle = $reseller->invoice_cycle ?? self::defaultCycle();
        $day = $reseller->invoice_cycle !== null ? $reseller->invoice_cycle_day : self::defaultDay();

        return [$cycle, $cycle->clampDay($day)];
    }

    /**
     * Masukkan semua baris belum ditagih ke draft (dibuat bila perlu) dan hapus draft otomatis yang kosong.
     *
     * @return array{created: int, attached: int, removed: int}
     */
    public function sync(bool $force = false): array
    {
        $result = ['created' => 0, 'attached' => 0, 'removed' => 0];

        if (! $force && ! self::enabled()) {
            return $result;
        }

        return DB::transaction(function () use ($result): array {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('sales-documents', 0))");

            // [reseller_id, tanggal, id] baris penjualan & retur kredit yang belum ditagih.
            $items = SalesOrderItem::query()
                ->whereNull('sales_order_items.reseller_invoice_id')
                ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
                ->join('stores', 'stores.id', '=', 'sales_orders.store_id')
                ->where('sales_orders.status', '!=', SalesOrderStatus::Cancelled)
                ->get(['sales_order_items.id', 'stores.reseller_id', DB::raw('sales_orders.order_date::date AS on_date')]);

            $returns = SalesReturn::query()
                ->whereNull('sales_returns.reseller_invoice_id')
                ->where('sales_returns.credit_reseller', true)
                ->join('sales_order_items', 'sales_order_items.id', '=', 'sales_returns.sales_order_item_id')
                ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
                ->join('stores', 'stores.id', '=', 'sales_orders.store_id')
                ->get(['sales_returns.id', 'stores.reseller_id', DB::raw('sales_returns.returned_at::date AS on_date')]);

            $resellers = Reseller::withTrashed()->whereIn('id', $items->pluck('reseller_id')->merge($returns->pluck('reseller_id'))->unique())->get()->keyBy('id');
            /** @var Collection<int, Collection<int, ResellerInvoice>> $drafts */
            $drafts = ResellerInvoice::query()->where('status', ResellerInvoiceStatus::Draft)->orderBy('period_start')->get()->groupBy('reseller_id');
            $targets = ['items' => [], 'returns' => []];

            foreach (['items' => $items, 'returns' => $returns] as $kind => $rows) {
                foreach ($rows as $row) {
                    $reseller = $resellers->get($row->reseller_id);

                    if ($reseller === null) {
                        continue;
                    }

                    $date = Carbon::parse($row->on_date)->toDateString();
                    $draft = ($drafts->get($reseller->id) ?? collect())
                        ->first(fn (ResellerInvoice $invoice): bool => $invoice->period_start->toDateString() <= $date && $invoice->period_end->toDateString() >= $date);

                    if ($draft === null) {
                        [$cycle, $day] = $this->cycleFor($reseller);
                        [$start, $end] = $cycle->period($date, $day);
                        $draft = ResellerInvoice::create([
                            'reseller_id' => $reseller->id,
                            'invoice_no' => $this->invoices->nextNumber($end),
                            'period_start' => $start->toDateString(),
                            'period_end' => $end->toDateString(),
                            'status' => ResellerInvoiceStatus::Draft,
                            'is_auto' => true,
                            'note' => 'Dibuat otomatis ('.$cycle->describe($day).').',
                            'created_by' => auth()->id(),
                        ]);
                        $drafts->put($reseller->id, ($drafts->get($reseller->id) ?? collect())->push($draft));
                        $result['created']++;
                    }

                    $targets[$kind][$draft->id][] = $row->id;
                }
            }

            foreach ($targets['items'] as $invoiceId => $ids) {
                foreach (array_chunk($ids, 1000) as $chunk) {
                    $result['attached'] += SalesOrderItem::query()->whereKey($chunk)->whereNull('reseller_invoice_id')->update(['reseller_invoice_id' => $invoiceId]);
                }
            }

            foreach ($targets['returns'] as $invoiceId => $ids) {
                foreach (array_chunk($ids, 1000) as $chunk) {
                    $result['attached'] += SalesReturn::query()->whereKey($chunk)->whereNull('reseller_invoice_id')->update(['reseller_invoice_id' => $invoiceId]);
                }
            }

            $result['removed'] = ResellerInvoice::query()
                ->where('status', ResellerInvoiceStatus::Draft)
                ->where('is_auto', true)
                ->whereDoesntHave('items')
                ->whereDoesntHave('returns')
                ->delete();

            return $result;
        });
    }

    /**
     * Sinkronkan sekali per request setelah respons terkirim (dipicu perubahan penjualan / retur).
     */
    public static function afterResponse(): void
    {
        if (! self::enabled() || app()->bound(self::SCHEDULED)) {
            return;
        }

        app()->instance(self::SCHEDULED, true);

        app()->terminating(function (): void {
            app()->forgetInstance(self::SCHEDULED);

            try {
                app(self::class)->sync();
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}
