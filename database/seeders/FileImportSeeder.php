<?php

namespace Database\Seeders;

use App\Enums\WarehouseKind;
use App\Imports\SkuAliasImport;
use App\Models\PriceTier;
use App\Models\Warehouse;
use App\Services\CatalogImporter;
use App\Services\StoreImporter;
use App\Support\Spreadsheet;
use Illuminate\Database\Seeder;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * Data awal dari file Excel di folder file_import, lewat importer yang sama dengan menu Import di aplikasi
 * (aman dijalankan ulang: data yang sudah ada diperbarui / dilewati, tidak dobel):
 *  1. Gudang default GBB (bahan baku) & GBJ (barang jadi);
 *  2. template-import-toko.xlsx: tier (sheet Daftar Tier), pemilik, reseller, channel, toko;
 *  3. Katalog Terisi.xlsx: bahan + stok awal, produk, resep (BOM), pembelian bahan; HPP & harga reseller produk
 *     produksi ikut dihitung dari resep mulai tanggal resep (beli jadi menunggu Barang Masuk sumber Beli Jadi);
 *  4. Import_Pemetaan_SKU_BigSeller.xlsx: pemetaan SKU BigSeller -> SKU varian.
 *
 * php artisan db:seed --class=FileImportSeeder
 */
class FileImportSeeder extends Seeder
{
    public const STORES = 'template-import-toko.xlsx';

    public const CATALOG = 'Katalog Terisi.xlsx';

    public const SKU_MAPPING = 'Import_Pemetaan_SKU_BigSeller.xlsx';

    public function run(StoreImporter $stores, CatalogImporter $catalog, SkuAliasImport $aliases): void
    {
        $this->warehouses();

        $this->tiers($this->path(self::STORES));
        $this->report('Toko', $stores->import($this->path(self::STORES), self::STORES));

        $this->report('Katalog', $catalog->import($this->path(self::CATALOG), self::CATALOG));

        $this->report('Pemetaan SKU', $aliases->import($this->path(self::SKU_MAPPING), self::SKU_MAPPING));
    }

    private function warehouses(): void
    {
        foreach ([['GBB', 'Gudang Bahan Baku', WarehouseKind::Bahan], ['GBJ', 'Gudang Barang Jadi', WarehouseKind::BarangJadi]] as [$code, $name, $kind]) {
            Warehouse::query()->updateOrCreate(['code' => $code], ['name' => $name, 'kind' => $kind, 'is_active' => true]);
        }

        $this->command?->info('Gudang: GBB (Gudang Bahan Baku), GBJ (Gudang Barang Jadi).');
    }

    /**
     * Tier harga dari sheet "Daftar Tier" (KODE TIER, NAMA TIER, PENYESUAIAN %), supaya toko tidak memakai tier baru
     * bawaan importer ("Belum diatur", +30%).
     */
    private function tiers(string $path): void
    {
        $reader = new Reader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== 'Daftar Tier') {
                    continue;
                }

                foreach ($sheet->getRowIterator() as $index => $row) {
                    [$code, $name, $pct] = array_pad($row->toArray(), 3, null);
                    $code = Spreadsheet::text($code);

                    if ($index === 1 || $code === '') {
                        continue;
                    }

                    PriceTier::query()->updateOrCreate(
                        ['code' => $code],
                        ['name' => Spreadsheet::text($name) ?: $code, 'adjustment_pct' => round((float) Spreadsheet::parseNumber($pct) / 100, 4)],
                    );
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  array{rows?: int, stats: array<string, array{created: int, updated: int, skipped: int}>, errors: list<string>, warnings?: list<string>}  $result
     */
    private function report(string $title, array $result): void
    {
        $stats = collect($result['stats'])
            ->map(fn (array $stat, string $label): string => "{$label} +{$stat['created']} ~{$stat['updated']} ={$stat['skipped']}")
            ->implode(', ');

        $this->command?->info("{$title}: ".($stats !== '' ? $stats : 'tidak ada perubahan').' (+ baru, ~ diperbarui, = dilewati)');
        $this->warnings("{$title} gagal", $result['errors']);
        $this->warnings("{$title} peringatan", $result['warnings'] ?? []);
    }

    /**
     * @param  list<string>  $messages
     */
    private function warnings(string $title, array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $this->command?->warn("{$title}: ".count($messages).' (detail baris gagal ada di menu Import Perlu Dicek)');

        foreach (array_slice($messages, 0, 10) as $message) {
            $this->command?->line("  - {$message}");
        }
    }

    private function path(string $file): string
    {
        $path = base_path('file_import/'.$file);

        if (! is_file($path)) {
            throw new RuntimeException("File {$file} tidak ditemukan di folder file_import.");
        }

        return $path;
    }
}
