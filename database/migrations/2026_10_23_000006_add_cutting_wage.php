<?php

use App\Enums\BomLineType;
use App\Models\ProductVariant;
use App\Models\WorkOrderItem;
use App\Services\CostingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * UPAH POTONG: tukang potong biasanya orang lain dari penjahit.
     *  - work_orders.cutter_id: tukang potong SPK (boleh kosong, dicatat saat upah potong dibayar);
     *  - work_order_items.cutting_cost_snapshot: ongkos POTONG per pcs dari resep saat dipotong;
     *  - tailor_payments.wage_type: jahit (default) / potong — upah jahit dan upah potong dibayar lewat menu yang sama.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('cutter_id')->nullable()->after('tailor_id')->index()->constrained('tailors');
        });

        Schema::table('work_order_items', function (Blueprint $table) {
            $table->decimal('cutting_cost_snapshot', 15, 2)->nullable()->after('sewing_cost_snapshot');
        });

        Schema::table('tailor_payments', function (Blueprint $table) {
            $table->string('wage_type', 10)->default('jahit')->after('work_order_id');
        });

        DB::statement("ALTER TABLE tailor_payments ADD CONSTRAINT tailor_payments_wage_type_check CHECK (wage_type IN ('jahit','potong'))");

        // SPK lama: ongkos POTONG dari resep yang berlaku pada tanggal potong.
        $costing = app(CostingService::class);

        WorkOrderItem::query()->with('workOrder')->each(function (WorkOrderItem $item) use ($costing): void {
            $variant = ProductVariant::withTrashed()->find($item->variant_id);
            $bom = $variant && $item->workOrder ? $costing->bomFor($variant, $item->workOrder->cut_date) : null;

            $cutting = $bom?->items
                ->filter(fn ($line): bool => $line->line_type === BomLineType::Labor && $line->laborType?->code === CostingService::CUTTING_LABOR_CODE)
                ->sum(fn ($line): float => (float) $line->labor_rate * (float) $line->qty_per_unit);

            $item->forceFill(['cutting_cost_snapshot' => round((float) $cutting, 2)])->saveQuietly();
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tailor_payments DROP CONSTRAINT tailor_payments_wage_type_check');

        Schema::table('tailor_payments', fn (Blueprint $table) => $table->dropColumn('wage_type'));
        Schema::table('work_order_items', fn (Blueprint $table) => $table->dropColumn('cutting_cost_snapshot'));
        Schema::table('work_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('cutter_id'));
    }
};
