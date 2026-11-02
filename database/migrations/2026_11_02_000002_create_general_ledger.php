<?php

use App\Support\ChartOfAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tabel sumber jurnal otomatis: setiap perubahan menandai buku besar perlu disusun ulang. */
    private const SOURCES = [
        'resellers', 'stores', 'sales_orders', 'sales_order_items', 'sales_returns', 'reseller_payments',
        'expenses', 'expense_categories', 'equity_transactions', 'material_purchases', 'material_purchase_items',
        'material_movements', 'stock_movements', 'work_orders', 'work_order_items', 'tailor_payments',
        'goods_receipts', 'goods_receipt_items', 'supplier_bill_lines', 'supplier_payments', 'store_ad_spends',
        'channel_fee_rates', 'variant_costs', 'stock_opnames', 'material_opnames', 'cash_accounts', 'accounts',
        'ledger_closings', 'settings',
    ];

    /**
     * BUKU BESAR (double-entry):
     *  - accounts: bagan akun per buku (konveksi / toko); role = pengikat aturan jurnal otomatis;
     *  - journal_entries + journal_lines: jurnal otomatis (disusun dari transaksi) & jurnal manual;
     *    store_id NULL = buku konveksi, terisi = buku toko tsb;
     *  - ledger_closings: bulan yang sudah ditutup (jurnal otomatis di periode itu tidak disusun ulang);
     *  - ledger_state: penanda transaksi berubah (trigger) -> jurnal otomatis disusun ulang saat dibaca.
     * Plus: "dibayar oleh" di pembelian bahan & upah, upah gabungan tanpa nama penjahit, opname bahan baku,
     * opname awal (selisihnya = saldo awal, bukan laba rugi).
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('ledger', 10)->default(ChartOfAccounts::KONVEKSI);
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('type', 15);
            $table->string('normal_balance', 6);
            $table->string('role', 40)->nullable();
            $table->foreignId('cash_account_id')->nullable()->unique()->constrained('cash_accounts')->cascadeOnDelete();
            $table->string('cash_flow', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('description')->nullable();
            $table->timestampsTz();

            $table->unique(['ledger', 'code']);
            $table->unique(['ledger', 'role']);
        });

        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_ledger_check CHECK (ledger IN ('konveksi','toko'))");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_type_check CHECK (type IN ('asset','liability','equity','revenue','cogs','expense','other_income','other_expense','tax'))");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_normal_balance_check CHECK (normal_balance IN ('debit','credit'))");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_cash_flow_check CHECK (cash_flow IS NULL OR cash_flow IN ('operating','investing','financing'))");

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained('stores');
            $table->date('entry_date');
            $table->string('number', 30)->nullable()->unique();
            $table->string('description');
            $table->string('source_type', 30);
            $table->string('source_key', 80)->nullable();
            $table->boolean('is_auto')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['entry_date', 'store_id']);
            $table->index(['source_type', 'source_key']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts');
            $table->date('entry_date');
            $table->foreignId('store_id')->nullable()->constrained('stores');
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->foreignId('reseller_id')->nullable()->constrained('resellers');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');
            $table->foreignId('tailor_id')->nullable()->constrained('tailors');
            $table->string('memo')->nullable();

            $table->index('entry_id');
            $table->index(['account_id', 'entry_date']);
            $table->index(['entry_date', 'store_id']);
            $table->index('reseller_id');
        });

        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_amount_check CHECK (debit >= 0 AND credit >= 0 AND (debit = 0 OR credit = 0) AND debit + credit > 0)');

        Schema::create('ledger_closings', function (Blueprint $table) {
            $table->id();
            $table->date('month')->unique();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->useCurrent();
        });

        Schema::create('ledger_state', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('dirty_at', 6)->nullable();
            $table->timestampTz('synced_at', 6)->nullable();
            $table->jsonb('drift')->nullable();
        });

        DB::table('ledger_state')->insert(['id' => 1, 'dirty_at' => now()]);

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->constrained('accounts');
        });

        Schema::table('material_purchases', function (Blueprint $table) {
            $table->string('payment_method', 10)->default('cash');
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts');
            $table->foreignId('reseller_payment_id')->nullable()->index()->constrained('reseller_payments');
        });

        DB::statement("ALTER TABLE material_purchases ADD CONSTRAINT material_purchases_payment_method_check CHECK (payment_method IN ('cash','credit','reseller'))");
        DB::statement("ALTER TABLE material_purchases ADD CONSTRAINT material_purchases_payment_source_check CHECK ((payment_method <> 'reseller' OR reseller_payment_id IS NOT NULL) AND (payment_method <> 'credit' OR supplier_id IS NOT NULL))");

        Schema::table('tailor_payments', function (Blueprint $table) {
            $table->foreignId('reseller_payment_id')->nullable()->index()->constrained('reseller_payments');
        });

        DB::statement('ALTER TABLE tailor_payments ALTER COLUMN tailor_id DROP NOT NULL');

        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->boolean('is_opening')->default(false);
        });

        Schema::create('material_opnames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->index()->constrained('warehouses');
            $table->timestampTz('counted_at');
            $table->string('status', 10)->default('draft');
            $table->timestampTz('posted_at')->nullable();
            $table->boolean('is_opening')->default(false);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE material_opnames ADD CONSTRAINT material_opnames_status_check CHECK (status IN ('draft','posted'))");

        Schema::create('material_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opname_id')->constrained('material_opnames')->cascadeOnDelete();
            $table->foreignId('material_id')->index()->constrained('materials');
            $table->decimal('system_qty', 14, 3);
            $table->decimal('counted_qty', 14, 3);
            $table->decimal('diff', 14, 3)->storedAs('counted_qty - system_qty');

            $table->unique(['opname_id', 'material_id']);
        });

        DB::statement('ALTER TABLE material_opname_items ADD CONSTRAINT material_opname_items_counted_qty_check CHECK (counted_qty >= 0)');

        $this->seedAccounts();

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_ledger_dirty() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                UPDATE ledger_state SET dirty_at = clock_timestamp() WHERE id = 1;
                RETURN NULL;
            END $$;
            SQL);

        foreach (self::SOURCES as $table) {
            DB::statement("CREATE TRIGGER {$table}_ledger_dirty AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION trg_ledger_dirty()");
        }
    }

    public function down(): void
    {
        foreach (self::SOURCES as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_ledger_dirty ON {$table}");
        }

        DB::statement('DROP FUNCTION IF EXISTS trg_ledger_dirty()');

        Schema::dropIfExists('material_opname_items');
        Schema::dropIfExists('material_opnames');
        Schema::table('stock_opnames', fn (Blueprint $table) => $table->dropColumn('is_opening'));
        Schema::table('tailor_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('reseller_payment_id'));
        DB::statement('ALTER TABLE material_purchases DROP CONSTRAINT IF EXISTS material_purchases_payment_source_check');
        DB::statement('ALTER TABLE material_purchases DROP CONSTRAINT IF EXISTS material_purchases_payment_method_check');
        Schema::table('material_purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reseller_payment_id');
            $table->dropConstrainedForeignId('cash_account_id');
            $table->dropColumn('payment_method');
        });
        Schema::table('expense_categories', fn (Blueprint $table) => $table->dropConstrainedForeignId('account_id'));
        Schema::dropIfExists('ledger_state');
        Schema::dropIfExists('ledger_closings');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
    }

    /**
     * Bagan akun bawaan, 1 akun kas per rekening, dan kategori gaji (produksi -> HPP, karyawan -> beban).
     */
    private function seedAccounts(): void
    {
        $now = now();

        foreach (ChartOfAccounts::defaults() as $ledger => $accounts) {
            foreach ($accounts as [$code, $name, $type, $normal, $role, $cashFlow]) {
                DB::table('accounts')->insert([
                    'ledger' => $ledger, 'code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normal,
                    'role' => $role, 'cash_flow' => $cashFlow, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        foreach (DB::table('cash_accounts')->orderBy('id')->get() as $index => $cash) {
            DB::table('accounts')->insert([
                'ledger' => ChartOfAccounts::KONVEKSI, 'code' => sprintf('1-11%02d', $index + 1), 'name' => 'Kas - '.$cash->name,
                'type' => 'asset', 'normal_balance' => 'debit', 'cash_account_id' => $cash->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $account = fn (string $role): int => (int) DB::table('accounts')->where('ledger', ChartOfAccounts::KONVEKSI)->where('role', $role)->value('id');

        foreach ([['GAJI-PRODUKSI', 'Gaji Produksi', 'production_overhead'], ['GAJI', 'Gaji Karyawan', 'salary_expense']] as [$code, $name, $role]) {
            if (DB::table('expense_categories')->where('code', $code)->doesntExist()) {
                DB::table('expense_categories')->insert(['code' => $code, 'name' => $name, 'account_id' => $account($role), 'created_at' => $now, 'updated_at' => $now]);
            } else {
                DB::table('expense_categories')->where('code', $code)->whereNull('account_id')->update(['account_id' => $account($role)]);
            }
        }
    }
};
