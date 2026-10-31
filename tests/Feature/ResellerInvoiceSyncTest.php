<?php

use App\Enums\InvoiceCycle;
use App\Enums\ResellerInvoiceStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ResellerInvoice;
use App\Models\SalesOrderItem;
use App\Models\Setting;
use App\Services\InvoiceService;
use App\Services\InvoiceSync;
use App\Services\SalesOrderService;

/** @return array<string, string> periode draft per reseller: "mulai..selesai" => jumlah baris */
function draftPeriods(): array
{
    return ResellerInvoice::query()->where('status', ResellerInvoiceStatus::Draft)->withCount('items')->orderBy('period_start')->get()
        ->mapWithKeys(fn (ResellerInvoice $invoice): array => [$invoice->period_start->toDateString().'..'.$invoice->period_end->toDateString() => $invoice->items_count])
        ->all();
}

it('computes weekly and monthly cycle periods from the chosen start day', function () {
    expect(array_map(fn ($date) => $date->toDateString(), InvoiceCycle::Monthly->period('2026-10-05', 1)))->toBe(['2026-10-01', '2026-10-31'])
        ->and(array_map(fn ($date) => $date->toDateString(), InvoiceCycle::Monthly->period('2026-10-05', 26)))->toBe(['2026-09-26', '2026-10-25'])
        ->and(array_map(fn ($date) => $date->toDateString(), InvoiceCycle::Monthly->period('2026-10-27', 26)))->toBe(['2026-10-26', '2026-11-25'])
        ->and(array_map(fn ($date) => $date->toDateString(), InvoiceCycle::Weekly->period('2026-10-07', 1)))->toBe(['2026-10-05', '2026-10-11']) // Rabu -> Senin..Minggu
        ->and(array_map(fn ($date) => $date->toDateString(), InvoiceCycle::Weekly->period('2026-10-07', 5)))->toBe(['2026-10-02', '2026-10-08']); // mulai Jumat
});

it('puts every unbilled sale into an automatic monthly draft, creating drafts as needed', function () {
    $f = businessFixture();
    fixtureOrder($f, 2, '2026-10-05');
    fixtureOrder($f, 3, '2026-10-20');
    fixtureOrder($f, 1, '2026-11-02');

    $result = app(InvoiceSync::class)->sync();

    expect($result)->toBe(['created' => 2, 'attached' => 3, 'removed' => 0])
        ->and(draftPeriods())->toBe(['2026-10-01..2026-10-31' => 2, '2026-11-01..2026-11-30' => 1])
        ->and(ResellerInvoice::query()->where('is_auto', true)->count())->toBe(2)
        ->and(app(InvoiceSync::class)->sync())->toBe(['created' => 0, 'attached' => 0, 'removed' => 0]); // aman diulang
});

it('uses an existing manual draft first and the reseller own weekly cycle otherwise', function () {
    $f = businessFixture();
    $f['reseller']->update(['invoice_cycle' => InvoiceCycle::Weekly, 'invoice_cycle_day' => 1]);
    app(InvoiceService::class)->generate(['reseller_id' => $f['reseller']->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-10']);
    fixtureOrder($f, 2, '2026-10-07'); // masuk draft manual
    fixtureOrder($f, 3, '2026-10-14'); // minggu Senin 12 - Minggu 18

    app(InvoiceSync::class)->sync();

    expect(draftPeriods())->toBe(['2026-10-01..2026-10-10' => 1, '2026-10-12..2026-10-18' => 1]);
});

it('never touches an issued invoice: late sales go to a new draft', function () {
    $f = businessFixture();
    fixtureOrder($f, 2, '2026-10-05');
    app(InvoiceSync::class)->sync();
    $issued = ResellerInvoice::query()->sole();
    app(InvoiceService::class)->issue($issued);

    fixtureOrder($f, 4, '2026-10-06'); // susulan
    app(InvoiceSync::class)->sync();

    expect($issued->fresh()->items()->count())->toBe(1)
        ->and(ResellerInvoice::query()->where('status', ResellerInvoiceStatus::Draft)->sole()->items()->sole()->qty)->toBe(4);
});

it('syncs right after a sale is saved, and removes an automatic draft emptied by a cancellation', function () {
    $f = businessFixture();
    $order = fixtureOrder($f, 2, '2026-10-05');
    expect(SalesOrderItem::query()->sole()->reseller_invoice_id)->toBeNull(); // dijalankan setelah respons

    app()->terminate();
    expect(draftPeriods())->toBe(['2026-10-01..2026-10-31' => 1]);

    app(SalesOrderService::class)->cancel($order);
    app()->terminate();
    expect(ResellerInvoice::query()->count())->toBe(0);
});

it('does nothing automatically when auto invoicing is switched off, and validates the cycle setting', function () {
    $f = businessFixture();
    Setting::updateOrCreate(['key' => 'invoice_auto'], ['value' => 0]);
    fixtureOrder($f, 2, '2026-10-05');
    app()->terminate();

    expect(ResellerInvoice::query()->count())->toBe(0)
        ->and(app(InvoiceSync::class)->sync(force: true)['created'])->toBe(1)
        ->and(fn () => Setting::updateOrCreate(['key' => 'invoice_cycle'], ['value' => 'tahunan']))->toThrow(BusinessRuleException::class);
});

it('lets a manually created invoice take over the rows of automatic drafts in its period', function () {
    $f = businessFixture();
    fixtureOrder($f, 2, '2026-10-05');
    fixtureOrder($f, 3, '2026-11-02');
    app(InvoiceSync::class)->sync(); // draft otomatis Oktober & November

    $manual = app(InvoiceService::class)->generate(['reseller_id' => $f['reseller']->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']);

    expect($manual->items()->count())->toBe(1)
        ->and(draftPeriods())->toBe(['2026-10-01..2026-10-31' => 1, '2026-11-01..2026-11-30' => 1])
        ->and(ResellerInvoice::query()->where('is_auto', true)->count())->toBe(1); // draft otomatis Oktober yang kosong dihapus
});
