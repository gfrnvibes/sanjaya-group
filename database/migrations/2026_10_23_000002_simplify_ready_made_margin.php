<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Beli jadi: margin nominal (Rp2.000) dihapus — harga dasar = (harga beli + packing) x (1 + margin %),
     * margin default 5% (pengaturan margin_ready_made_pct), packing default Rp3.000 (packing_cost_default);
     * keduanya bisa diubah di Pengaturan atau per varian / model.
     */
    public function up(): void
    {
        DB::table('settings')->where('key', 'margin_ready_made_amount')->delete();
        DB::table('settings')->updateOrInsert(['key' => 'packing_cost_default'], ['value' => json_encode(3000), 'updated_at' => now()]);
        DB::table('settings')->insertOrIgnore(['key' => 'margin_ready_made_pct', 'value' => json_encode(0.05), 'updated_at' => now()]);

        $this->pricingView();
    }

    public function down(): void
    {
        DB::table('settings')->insertOrIgnore(['key' => 'margin_ready_made_amount', 'value' => json_encode(2000), 'updated_at' => now()]);
        DB::table('settings')->where('key', 'packing_cost_default')->update(['value' => json_encode(0)]);
    }

    /**
     * v_variant_pricing (usulan harga) mengikuti PricingService::basePriceFor(): HPP x (1 + margin), dibulatkan ke atas.
     */
    private function pricingView(): void
    {
        $setting = fn (string $key): string => "(SELECT (value #>> '{}')::numeric FROM settings WHERE key = '{$key}')";
        $margin = "CASE WHEN m.kind = 'beli_jadi' THEN COALESCE(v.margin_pct, m.default_margin_pct, s.margin_ready_made, 0.05)
                        ELSE COALESCE(v.margin_pct, m.default_margin_pct, s.margin_default, 0.30) END";

        DB::statement('DROP VIEW IF EXISTS v_variant_pricing');
        DB::statement("
            CREATE VIEW v_variant_pricing AS
            WITH c AS (SELECT DISTINCT ON (variant_id) variant_id, total_cost FROM variant_costs
                       WHERE effective_from <= current_date ORDER BY variant_id, effective_from DESC),
                 p AS (SELECT DISTINCT ON (variant_id) variant_id, base_price FROM reseller_price_histories
                       WHERE effective_from <= current_date ORDER BY variant_id, effective_from DESC),
                 s AS (SELECT {$setting('margin_default')} AS margin_default,
                              {$setting('margin_ready_made_pct')} AS margin_ready_made,
                              {$setting('price_rounding')} AS rounding)
            SELECT v.id AS variant_id, v.sku, c.total_cost AS hpp,
                   {$margin} AS margin_pct,
                   p.base_price AS current_price,
                   CEIL(c.total_cost * (1 + {$margin})
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
