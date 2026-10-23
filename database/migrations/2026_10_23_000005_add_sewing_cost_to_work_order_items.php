<?php

use App\Enums\BomLineType;
use App\Models\ProductVariant;
use App\Models\WorkOrderItem;
use App\Services\CostingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upah penjahit = hanya ongkos JAHIT dari resep (ongkos potong/obras/bordir biasanya orang lain, dibayar terpisah).
     * labor_cost_snapshot tetap total semua ongkos (untuk HPP); sewing_cost_snapshot = ongkos JAHIT per pcs (untuk upah).
     */
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->decimal('sewing_cost_snapshot', 15, 2)->nullable()->after('labor_cost_snapshot');
        });

        // SPK lama: ongkos JAHIT dari resep yang berlaku pada tanggal potong.
        $costing = app(CostingService::class);

        WorkOrderItem::query()->with('workOrder')->each(function (WorkOrderItem $item) use ($costing): void {
            $variant = ProductVariant::withTrashed()->find($item->variant_id);
            $bom = $variant && $item->workOrder ? $costing->bomFor($variant, $item->workOrder->cut_date) : null;

            $sewing = $bom?->items
                ->filter(fn ($line): bool => $line->line_type === BomLineType::Labor && $line->laborType?->code === CostingService::SEWING_LABOR_CODE)
                ->sum(fn ($line): float => (float) $line->labor_rate * (float) $line->qty_per_unit);

            $item->forceFill(['sewing_cost_snapshot' => round((float) $sewing, 2)])->saveQuietly();
        });
    }

    public function down(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropColumn('sewing_cost_snapshot');
        });
    }
};
