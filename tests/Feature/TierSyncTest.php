<?php

use App\Enums\ResellerInvoiceStatus;
use App\Models\OwnerTierHistory;
use App\Models\PriceTier;
use App\Models\ResellerInvoice;
use App\Models\Setting;
use App\Services\TierRepricer;

beforeEach(function () {
    Setting::updateOrCreate(['key' => 'tier_price_rounding'], ['value' => 1]);
    $this->f = businessFixture();
    $this->tier = PriceTier::create(['code' => 'T2', 'name' => 'Tier 2', 'adjustment_pct' => 0.10]);
});

it('reprices sales from the effective date as soon as an owner tier is added, edited or removed', function () {
    $before = fixtureOrder($this->f, 2, '2026-09-20')->items()->sole();
    $item = fixtureOrder($this->f, 10, '2026-10-10')->items()->sole();
    expect((float) $item->reseller_unit_price)->toBe(100.0);

    $history = OwnerTierHistory::create(['owner_id' => $this->f['owner']->id, 'price_tier_id' => $this->tier->id, 'effective_from' => '2026-10-01']);
    expect((float) $item->refresh()->reseller_unit_price)->toBe(110.0)
        ->and((float) $item->tier_adjustment_pct)->toBe(0.1)
        ->and((float) $item->bill_amount)->toBe(1100.0)
        ->and((float) $before->refresh()->reseller_unit_price)->toBe(100.0);

    $this->tier->update(['adjustment_pct' => 0.20]);
    expect((float) $item->refresh()->reseller_unit_price)->toBe(120.0);

    $history->update(['effective_from' => '2026-09-01']);
    expect((float) $before->refresh()->reseller_unit_price)->toBe(120.0);

    $history->delete();
    expect((float) $item->refresh()->reseller_unit_price)->toBe(100.0)
        ->and((float) $before->refresh()->reseller_unit_price)->toBe(100.0);
});

it('keeps sales in an issued invoice locked but updates draft invoices', function () {
    $issued = fixtureOrder($this->f, 10, '2026-10-05')->items()->sole();
    $draft = fixtureOrder($this->f, 5, '2026-10-06')->items()->sole();
    $invoice = fn (string $no, ResellerInvoiceStatus $status): ResellerInvoice => ResellerInvoice::create([
        'reseller_id' => $this->f['reseller']->id, 'invoice_no' => $no, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => $status,
    ]);
    $issued->update(['reseller_invoice_id' => $invoice('INV-TERBIT', ResellerInvoiceStatus::Issued)->id]);
    $draft->update(['reseller_invoice_id' => $invoice('INV-DRAFT', ResellerInvoiceStatus::Draft)->id]);

    OwnerTierHistory::create(['owner_id' => $this->f['owner']->id, 'price_tier_id' => $this->tier->id, 'effective_from' => '2026-10-01']);

    expect((float) $issued->refresh()->reseller_unit_price)->toBe(100.0)
        ->and((float) $draft->refresh()->reseller_unit_price)->toBe(110.0)
        ->and(app(TierRepricer::class)->reprice([$this->f['owner']->id]))->toBe(['updated' => 0, 'locked' => 1]);
});
