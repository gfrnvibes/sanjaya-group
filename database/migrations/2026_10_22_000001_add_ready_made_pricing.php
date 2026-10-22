<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Produk BELI JADI: HPP = harga beli terakhir (Barang Masuk sumber Beli Jadi) + biaya packing;
     * harga dasar = HPP x (1 + margin beli jadi %) + margin beli jadi nominal.
     * Biaya packing: pengaturan packing_cost_default, bisa diubah per varian (product_variants.packing_cost).
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('packing_cost', 15, 2)->nullable()->after('margin_pct'); // NULL = ikut packing_cost_default
        });

        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_packing_cost_check CHECK (packing_cost IS NULL OR packing_cost >= 0)');

        DB::statement('ALTER TABLE variant_costs DROP CONSTRAINT variant_costs_source_check');
        DB::statement("ALTER TABLE variant_costs ADD CONSTRAINT variant_costs_source_check CHECK (source IN ('bom','legacy','manual','purchase'))");

        foreach (['packing_cost_default' => 0, 'margin_ready_made_pct' => 0.05, 'margin_ready_made_amount' => 2000] as $key => $value) {
            DB::table('settings')->insertOrIgnore(['key' => $key, 'value' => json_encode($value), 'updated_at' => now()]);
        }

        $this->pricingView(readyMade: true);
    }

    public function down(): void
    {
        $this->pricingView(readyMade: false);

        DB::table('settings')->whereIn('key', ['packing_cost_default', 'margin_ready_made_pct', 'margin_ready_made_amount'])->delete();

        DB::table('variant_costs')->where('source', 'purchase')->delete();
        DB::statement('ALTER TABLE variant_costs DROP CONSTRAINT variant_costs_source_check');
        DB::statement("ALTER TABLE variant_costs ADD CONSTRAINT variant_costs_source_check CHECK (source IN ('bom','legacy','manual'))");

        DB::statement('ALTER TABLE product_variants DROP CONSTRAINT product_variants_packing_cost_check');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('packing_cost');
        });
    }

    /**
     * v_variant_pricing (usulan harga) mengikuti rumus PricingService::basePriceFor().
     */
    private function pricingView(bool $readyMade): void
    {
        $setting = fn (string $key): string => "(SELECT (value #>> '{}')::numeric FROM settings WHERE key = '{$key}')";

        $margin = $readyMade
            ? "CASE WHEN m.kind = 'beli_jadi' THEN COALESCE(v.margin_pct, m.default_margin_pct, s.margin_ready_made, 0.05)
                    ELSE COALESCE(v.margin_pct, m.default_margin_pct, s.margin_default, 0.30) END"
            : 'COALESCE(v.margin_pct, m.default_margin_pct, s.margin_default, 0.30)';
        $amount = $readyMade ? "CASE WHEN m.kind = 'beli_jadi' THEN COALESCE(s.margin_ready_made_amount, 2000) ELSE 0 END" : '0';

        DB::statement('DROP VIEW IF EXISTS v_variant_pricing');
        DB::statement("
            CREATE VIEW v_variant_pricing AS
            WITH c AS (SELECT DISTINCT ON (variant_id) variant_id, total_cost FROM variant_costs
                       WHERE effective_from <= current_date ORDER BY variant_id, effective_from DESC),
                 p AS (SELECT DISTINCT ON (variant_id) variant_id, base_price FROM reseller_price_histories
                       WHERE effective_from <= current_date ORDER BY variant_id, effective_from DESC),
                 s AS (SELECT {$setting('margin_default')} AS margin_default,
                              {$setting('margin_ready_made_pct')} AS margin_ready_made,
                              {$setting('margin_ready_made_amount')} AS margin_ready_made_amount,
                              {$setting('price_rounding')} AS rounding)
            SELECT v.id AS variant_id, v.sku, c.total_cost AS hpp,
                   {$margin} AS margin_pct,
                   p.base_price AS current_price,
                   CEIL((c.total_cost * (1 + {$margin}) + {$amount})
                        / COALESCE(NULLIF(s.rounding, 0), 100)) * COALESCE(NULLIF(s.rounding, 0), 100) AS proposed_price
            FROM product_variants v
            JOIN product_models m ON m.id = v.model_id
            LEFT JOIN c ON c.variant_id = v.id
            LEFT JOIN p ON p.variant_id = v.id
            CROSS JOIN s
            WHERE v.deleted_at IS NULL
        ");
    }
};
