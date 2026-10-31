<?php

namespace App\Services;

use App\Enums\ResellerInvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ResellerInvoice;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TAGIHAN periode: kumpulkan baris penjualan & retur reseller yang belum ditagih.
 */
class InvoiceService
{
    /**
     * @param  array<string, mixed>  $data  reseller_id, period_start, period_end, due_date, note
     */
    public function generate(array $data): ResellerInvoice
    {
        return DB::transaction(function () use ($data): ResellerInvoice {
            $invoice = ResellerInvoice::create([
                ...$data,
                'invoice_no' => filled($data['invoice_no'] ?? null) ? $data['invoice_no'] : $this->nextNumber(Carbon::parse($data['period_end'])),
                'status' => ResellerInvoiceStatus::Draft,
                'created_by' => auth()->id(),
            ]);

            $this->collect($invoice);

            return $invoice;
        });
    }

    /**
     * Tarik baris yang belum ditagih ke tagihan draft (bisa diulang setelah ada penjualan baru).
     */
    public function collect(ResellerInvoice $invoice): int
    {
        return DB::transaction(function () use ($invoice): int {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('sales-documents', 0))");
            $invoice = ResellerInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->ensureDraft($invoice);

            // Baris belum ditagih + baris di draft OTOMATIS reseller ini (tagihan manual mengambil alih periodenya).
            $autoDrafts = ResellerInvoice::query()
                ->where('reseller_id', $invoice->reseller_id)
                ->where('status', ResellerInvoiceStatus::Draft)
                ->where('is_auto', true)
                ->whereKeyNot($invoice->id)
                ->pluck('id')
                ->all();
            $unbilled = fn (Builder $query) => $query->whereNull('reseller_invoice_id')
                ->when($autoDrafts !== [], fn (Builder $query) => $query->orWhereIn('reseller_invoice_id', $autoDrafts));

            $items = SalesOrderItem::query()
                ->where($unbilled)
                ->whereHas('salesOrder', fn (Builder $order) => $order
                    ->where('status', '!=', SalesOrderStatus::Cancelled)
                    ->whereBetween('order_date', [$invoice->period_start, $invoice->period_end])
                    ->whereHas('store', fn (Builder $store) => $store->where('reseller_id', $invoice->reseller_id)))
                ->update(['reseller_invoice_id' => $invoice->id]);

            $returns = SalesReturn::query()
                ->where($unbilled)
                ->where('credit_reseller', true)
                ->whereBetween('returned_at', [$invoice->period_start, $invoice->period_end])
                ->whereHas('salesOrderItem.salesOrder.store', fn (Builder $store) => $store->where('reseller_id', $invoice->reseller_id))
                ->update(['reseller_invoice_id' => $invoice->id]);

            // Draft otomatis yang kosong setelah diambil alih dihapus.
            ResellerInvoice::query()->whereKey($autoDrafts)->whereDoesntHave('items')->whereDoesntHave('returns')->delete();

            return $items + $returns;
        });
    }

    public function issue(ResellerInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('sales-documents', 0))");
            $locked = ResellerInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->ensureDraft($locked);
            $locked->update(['status' => ResellerInvoiceStatus::Issued, 'issued_at' => $locked->issued_at ?? today()]);
        });
        $invoice->refresh();
    }

    public function void(ResellerInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('sales-documents', 0))");
            $invoice = ResellerInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status === ResellerInvoiceStatus::Void) {
                throw new BusinessRuleException('Tagihan sudah dibatalkan.');
            }

            $invoice->items()->update(['reseller_invoice_id' => null]);
            $invoice->returns()->update(['reseller_invoice_id' => null]);
            $invoice->update(['status' => ResellerInvoiceStatus::Void]);
        });
    }

    public function nextNumber(Carbon $period): string
    {
        return DocumentNumber::next('INV/'.$period->format('Ym').'/', 'reseller_invoices', 'invoice_no');
    }

    private function ensureDraft(ResellerInvoice $invoice): void
    {
        if ($invoice->status !== ResellerInvoiceStatus::Draft) {
            throw new BusinessRuleException('Hanya tagihan draft yang bisa diubah.');
        }
    }
}
