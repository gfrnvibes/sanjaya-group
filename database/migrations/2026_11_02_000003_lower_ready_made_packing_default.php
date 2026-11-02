<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Packing default produk beli jadi Rp3.000 -> Rp2.000 (pengaturan packing_cost_default).
     * Hanya bila masih nilai lama; HPP & harga lama tidak berubah sampai harga dihitung ulang.
     */
    public function up(): void
    {
        DB::table('settings')->where('key', 'packing_cost_default')->where('value', json_encode(3000))
            ->update(['value' => json_encode(2000), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'packing_cost_default')->where('value', json_encode(2000))
            ->update(['value' => json_encode(3000), 'updated_at' => now()]);
    }
};
