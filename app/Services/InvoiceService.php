<?php

namespace App\Services;

use App\Enums\ResellerInvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ResellerInvoice;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
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
                'invoice_no' => $data['invoice_no'] ?? $this->nextNumber(Carbon::parse($data['period_end'])),
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
        $this->ensureDraft($invoice);

        $items = SalesOrderItem::query()
            ->whereNull('reseller_invoice_id')
            ->whereHas('salesOrder', fn (Builder $order) => $order
                ->where('status', '!=', SalesOrderStatus::Cancelled)
                ->whereBetween('order_date', [$invoice->period_start, $invoice->period_end])
                ->whereHas('store', fn (Builder $store) => $store->where('reseller_id', $invoice->reseller_id)))
            ->update(['reseller_invoice_id' => $invoice->id]);

        $returns = SalesReturn::query()
            ->whereNull('reseller_invoice_id')
            ->where('credit_reseller', true)
            ->whereBetween('returned_at', [$invoice->period_start, $invoice->period_end])
            ->whereHas('salesOrderItem.salesOrder.store', fn (Builder $store) => $store->where('reseller_id', $invoice->reseller_id))
            ->update(['reseller_invoice_id' => $invoice->id]);

        return $items + $returns;
    }

    public function issue(ResellerInvoice $invoice): void
    {
        $this->ensureDraft($invoice);

        $invoice->update(['status' => ResellerInvoiceStatus::Issued, 'issued_at' => $invoice->issued_at ?? today()]);
    }

    public function void(ResellerInvoice $invoice): void
    {
        if ($invoice->status === ResellerInvoiceStatus::Void) {
            throw new BusinessRuleException('Tagihan sudah dibatalkan.');
        }

        DB::transaction(function () use ($invoice): void {
            $invoice->items()->update(['reseller_invoice_id' => null]);
            $invoice->returns()->update(['reseller_invoice_id' => null]);
            $invoice->update(['status' => ResellerInvoiceStatus::Void]);
        });
    }

    public function nextNumber(Carbon $period): string
    {
        $prefix = 'INV/'.$period->format('Ym').'/';
        $last = ResellerInvoice::query()->where('invoice_no', 'like', $prefix.'%')->orderByDesc('invoice_no')->value('invoice_no');
        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function ensureDraft(ResellerInvoice $invoice): void
    {
        if ($invoice->status !== ResellerInvoiceStatus::Draft) {
            throw new BusinessRuleException('Hanya tagihan draft yang bisa diubah.');
        }
    }
}
