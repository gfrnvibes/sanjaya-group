<?php

namespace App\Support;

/**
 * BAGAN AKUN bawaan (konveksi & toko) dan peran sistem yang dipakai posting jurnal otomatis.
 * Peran (role) mengikat akun ke aturan jurnal; kode & nama bebas diubah dari menu Bagan Akun.
 */
class ChartOfAccounts
{
    public const KONVEKSI = 'konveksi';

    public const STORE = 'toko';

    public const LEDGERS = [self::KONVEKSI => 'Konveksi', self::STORE => 'Toko'];

    public const TYPES = [
        'asset' => 'Aset',
        'liability' => 'Kewajiban',
        'equity' => 'Ekuitas',
        'revenue' => 'Pendapatan',
        'cogs' => 'Harga Pokok Penjualan',
        'expense' => 'Beban Operasional',
        'other_income' => 'Pendapatan Lain-lain',
        'other_expense' => 'Beban Lain-lain',
        'tax' => 'Pajak Penghasilan',
    ];

    /** Jenis akun laba rugi (saldo ditutup ke laba ditahan tiap akhir tahun). */
    public const PROFIT_TYPES = ['revenue', 'cogs', 'expense', 'other_income', 'other_expense', 'tax'];

    public const CASH_FLOWS = ['operating' => 'Operasi', 'investing' => 'Investasi', 'financing' => 'Pendanaan'];

    /**
     * [kode, nama, jenis, saldo normal, peran, arus kas]
     *
     * @return array<string, list<array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: ?string}>>
     */
    public static function defaults(): array
    {
        return [
            self::KONVEKSI => [
                ['1-1199', 'Kas Belum Ditentukan Rekening', 'asset', 'debit', 'cash_unassigned', null],
                ['1-1200', 'Piutang Reseller', 'asset', 'debit', 'ar_reseller', 'operating'],
                ['1-1290', 'Dibayar Reseller - Belum Dirinci', 'asset', 'debit', 'offset_clearing', 'operating'],
                ['1-1300', 'Persediaan Bahan Baku', 'asset', 'debit', 'inventory_material', 'operating'],
                ['1-1310', 'Barang Dalam Proses (SPK)', 'asset', 'debit', 'wip', 'operating'],
                ['1-1320', 'Persediaan Barang Jadi', 'asset', 'debit', 'inventory_fg', 'operating'],
                ['1-1500', 'Piutang Karyawan (Kasbon)', 'asset', 'debit', null, 'operating'],
                ['1-2000', 'Aset Tetap', 'asset', 'debit', null, 'investing'],
                ['1-2100', 'Akumulasi Penyusutan', 'asset', 'credit', null, 'investing'],
                ['2-1100', 'Utang Supplier', 'liability', 'credit', 'ap_supplier', 'operating'],
                ['2-1200', 'Utang Upah Jahit & Potong', 'liability', 'credit', 'wages_payable', 'operating'],
                ['2-1300', 'Utang Gaji', 'liability', 'credit', null, 'operating'],
                ['2-1400', 'Utang Pajak', 'liability', 'credit', 'tax_payable', 'operating'],
                ['2-2000', 'Pinjaman', 'liability', 'credit', null, 'financing'],
                ['3-1000', 'Modal Disetor', 'equity', 'credit', 'equity_capital', 'financing'],
                ['3-2000', 'Prive', 'equity', 'debit', 'equity_drawing', 'financing'],
                ['3-3000', 'Laba Ditahan', 'equity', 'credit', 'retained_earnings', 'financing'],
                ['3-9000', 'Saldo Awal', 'equity', 'credit', 'opening_equity', 'financing'],
                ['4-1000', 'Penjualan ke Reseller', 'revenue', 'credit', 'sales', 'operating'],
                ['4-1100', 'Retur Penjualan', 'revenue', 'debit', 'sales_return', 'operating'],
                ['5-1000', 'HPP Produk Produksi', 'cogs', 'debit', 'cogs_production', 'operating'],
                ['5-1100', 'HPP Produk Beli Jadi', 'cogs', 'debit', 'cogs_ready_made', 'operating'],
                ['5-1200', 'Selisih Persediaan (opname & penyesuaian)', 'cogs', 'debit', 'inventory_variance', 'operating'],
                ['5-1210', 'Selisih Produksi (reject & SPK batal)', 'cogs', 'debit', 'production_variance', 'operating'],
                ['5-1300', 'Biaya Overhead Produksi', 'cogs', 'debit', 'production_overhead', 'operating'],
                // Belanja yang sudah ditaksir di resep (ongkos AKSESORIS): resep mengkredit Selisih Produksi, belanja aslinya di sini.
                ['5-1310', 'Biaya Aksesoris (sudah di resep)', 'cogs', 'debit', 'recipe_cost', 'operating'],
                ['6-1100', 'Beban Gaji Karyawan', 'expense', 'debit', 'salary_expense', 'operating'],
                ['6-1900', 'Beban Operasional Lain-lain', 'expense', 'debit', 'expense_default', 'operating'],
                ['6-2000', 'Beban Penyusutan', 'expense', 'debit', null, 'operating'],
                ['7-1000', 'Pendapatan Lain-lain', 'other_income', 'credit', null, 'operating'],
                ['7-1100', 'Penjualan Titipan Grosir', 'other_income', 'credit', 'consignment_sales', 'operating'],
                ['7-2000', 'Beban Lain-lain & Bunga', 'other_expense', 'debit', null, 'operating'],
                ['7-2100', 'Harga Pokok Titipan Grosir', 'other_expense', 'debit', 'consignment_cogs', 'operating'],
                ['8-1000', 'Beban Pajak Penghasilan', 'tax', 'debit', 'tax_expense', 'operating'],
            ],
            self::STORE => [
                ['1-1100', 'Kas / Saldo Marketplace', 'asset', 'debit', 'store_cash', null],
                ['1-1200', 'Penjualan Menunggu Data Pendapatan', 'asset', 'debit', 'store_pending', 'operating'],
                ['2-1100', 'Utang ke Konveksi', 'liability', 'credit', 'store_payable', 'operating'],
                ['2-1400', 'Utang Pajak', 'liability', 'credit', 'store_tax_payable', 'operating'],
                ['3-1000', 'Modal Pemilik Toko', 'equity', 'credit', null, 'financing'],
                ['3-3000', 'Laba Ditahan', 'equity', 'credit', 'store_retained_earnings', 'financing'],
                ['3-9000', 'Saldo Awal', 'equity', 'credit', 'store_opening_equity', 'financing'],
                ['4-1000', 'Penjualan Toko (setelah refund)', 'revenue', 'credit', 'store_sales', 'operating'],
                ['5-1000', 'HPP - Harga dari Konveksi', 'cogs', 'debit', 'store_cogs', 'operating'],
                ['6-1000', 'Beban Admin Marketplace', 'expense', 'debit', 'store_admin_fee', 'operating'],
                ['6-1100', 'Beban Iklan', 'expense', 'debit', 'store_ads', 'operating'],
                ['8-1000', 'Beban Pajak Penghasilan', 'tax', 'debit', 'store_tax_expense', 'operating'],
            ],
        ];
    }

    /** Saldo normal bawaan per jenis akun. */
    public static function normalBalance(string $type): string
    {
        return in_array($type, ['asset', 'cogs', 'expense', 'other_expense', 'tax'], true) ? 'debit' : 'credit';
    }
}
