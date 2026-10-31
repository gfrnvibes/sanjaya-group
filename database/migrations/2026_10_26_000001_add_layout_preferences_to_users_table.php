<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preferensi tampilan panel per user: lebar konten & kepadatan tabel.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('layout_width', 10)->default('standard');
            $table->string('table_density', 12)->default('comfortable');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_layout_width_check CHECK (layout_width IN ('standard','wide','full'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_table_density_check CHECK (table_density IN ('comfortable','compact'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_layout_width_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_table_density_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['layout_width', 'table_density']);
        });
    }
};
