# Rancangan Database Website (Laravel 13 + Filament 5 + PostgreSQL 18)

Versi 2 – 16 Okt 2026. DDL lengkap (50 tabel + trigger + view laporan) ada di file `rancangan_database_pgsql.sql` (dikirim di chat). Sudah diuji di PostgreSQL 16: schema terpasang tanpa error, data contoh menghasilkan stok, tagihan, laba rugi konveksi, laba rugi toko, piutang, usulan harga, dan prioritas produksi yang benar.

## Prinsip

- 3NF: pemilik/reseller/channel penjualan tidak disalin ke baris penjualan; diturunkan dari stores → resellers → owners.
- Denormalisasi disengaja hanya SNAPSHOT historis di sales_order_items: reseller_price_id, tier_adjustment_pct, reseller_unit_price, hpp_unit (pengganti "BERLAKU MULAI" Excel; laporan lama tidak berubah).
- Riwayat (effective_from): reseller_price_histories, variant_costs (HPP), owner_tier_histories, channel_fee_rates, boms.
- Stok = ledger append-only (stock_movements, material_movements) + cache saldo (stock_balances, material_balances) diisi TRIGGER; UPDATE/DELETE ledger ditolak trigger (koreksi = baris baru).
- Kolom turunan = GENERATED STORED: unit_cost pembelian, total_cost HPP, bill_amount (tagihan), margin, selisih opname.
- Uang numeric(15,2), persen numeric(7,4), qty barang integer, qty bahan numeric(14,3). Enum = varchar + CHECK. UNIQUE NULLS NOT DISTINCT untuk kombinasi model/warna/ukuran.

## Modul & tabel

0. Akses: owners, users(owner_id null = staf konveksi), settings(jsonb), + spatie permission & activitylog.
1. Reseller & toko: resellers(opening_balance), channels, channel_fee_rates, stores(reseller+channel; contoh "ARR SANJAYA WA"), price_tiers, owner_tier_histories.
2. Produk: product_categories, product_models(kind produksi/beli_jadi/jasa, default_margin_pct), colors, sizes, product_variants(sku, min_stock, margin_pct), sku_aliases (pemetaan BigSeller).
3. Bahan & HPP: suppliers, material_types, materials, material_purchases + items, labor_types, boms + bom_items (material follow_variant/fixed atau labor), variant_costs (source bom/legacy/manual), reseller_price_histories (source auto/manual/import, approved_by).
4. Stok: warehouses, stock_movements, stock_balances, material_movements, material_balances, goods_receipts + items (BARANG MASUK manual), stock_adjustments + items (PENYESUAIAN), stock_opnames + items.
5. Produksi: tailors, work_orders (SPK) + work_order_items (qty_cut/good/reject, snapshot biaya), cash_accounts, tailor_payments.
6. Penjualan: sales_import_batches, sales_orders (UQ store+order_no, platform_fee aktual), sales_order_items (snapshot harga, bill_amount, margin, reseller_invoice_id), sales_returns (restock, credit_reseller), store_ad_spends.
7. Tagihan & bayar: reseller_invoices (periode), reseller_payments (transfer/cash/cost_offset).
8. Biaya konveksi: expense_categories, expenses (store_id/reseller_id opsional; termasuk gaji admin toko, packing, ongkir; reseller_payment_id bila dibayar reseller).

## Laporan (view/fungsi)

- fn_base_price, fn_owner_tier_pct (dipakai saat simpan penjualan untuk isi snapshot).
- v_variant_pricing: HPP berlaku, margin, harga berlaku, usulan harga (pengganti UPDATE HARGA).
- v_sales_lines: join penjualan sekali untuk semua laporan.
- v_konveksi_monthly_pl, v_store_monthly_pl (fee aktual bila ada, else estimasi % channel), v_reseller_ar (piutang).
- fn_production_priority(hari, target, lead, kelipatan, tanggal) → terlaris & prioritas produksi.

## Alur aplikasi (service layer Laravel, 1 DB transaction per aksi)

- Simpan/impor penjualan: map SKU (sku_aliases) → ambil harga dasar + tier per tanggal → isi snapshot → stock_movements sale_out.
- Cancel/retur: stock_movements sale_cancel/return_in + sales_returns (kredit tagihan).
- Pembelian bahan → material_movements purchase_in. SPK dipotong → production_out per bom_item; SPK selesai → production_in.
- Job harian: hitung variant_costs dari BOM × harga beli terakhir → v_variant_pricing → usulan harga → approve di Filament → reseller_price_histories (effective_from = tanggal approve/besok).
- Panel Filament: /admin (konveksi) dan /reseller (tenancy per owner: toko sendiri, penjualan, tagihan, pembayaran, laba toko).

## Migrasi dari Excel

products/variants/aliases dari MASTER PRODUK & PEMETAAN SKU; resellers/stores/owners dari MASTER RESELLER/TOKO; harga dari HARGA RESELLER; HPP LAMA → variant_costs legacy; stok awal → stock_movements opening; INPUT PENJUALAN → sales_orders/items (snapshot = nilai Excel); INPUT PEMBAYARAN → reseller_payments.
