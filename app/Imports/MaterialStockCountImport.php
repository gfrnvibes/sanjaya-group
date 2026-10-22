<?php

namespace App\Imports;

use App\Enums\MaterialMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Material;
use App\Models\MaterialBalance;
use App\Models\Warehouse;
use App\Services\StockLedger;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * STOK OPNAME BAHAN lewat Excel: export saldo bahan -> isi STOK FISIK -> import.
 * Selisih (fisik - saldo terkini) dicatat ke kartu stok bahan sebagai mutasi opname bertanggal TANGGAL OPNAME.
 * Baris dengan STOK FISIK kosong diabaikan; impor ulang file yang sama tidak menggandakan (selisih sudah 0).
 */
class MaterialStockCountImport extends RowImporter
{
    public function __construct(private StockLedger $ledger) {}

    public function title(): string
    {
        return 'Opname Bahan';
    }

    public function columns(): array
    {
        return [
            'KODE GUDANG' => ['warehouse', 'Ya', 'Kode gudang bahan (jangan diubah).'],
            'KODE BAHAN' => ['code', 'Ya', 'Kode bahan (jangan diubah).'],
            'SATUAN' => ['uom', '-', 'Satuan dasar (info). STOK FISIK diisi dalam satuan ini, mis. cm.'],
            'STOK SISTEM' => ['system_qty', '-', 'Saldo menurut sistem saat file di-export (info).'],
            'STOK FISIK' => ['counted_qty', 'Untuk bahan yang dihitung', 'Hasil hitung fisik dalam satuan dasar (>= 0). Kosong = tidak dihitung, diabaikan.'],
            'TANGGAL OPNAME' => ['counted_at', '-', 'Waktu hitung fisik (YYYY-MM-DD HH:MM:SS, WIB). Kosong = saat impor.'],
            'CATATAN' => ['note', '-', ''],
        ];
    }

    protected function systemColumns(): array
    {
        return ['SATUAN', 'STOK SISTEM'];
    }

    protected function requiredHeaders(): array
    {
        return ['warehouse', 'code', 'counted_qty'];
    }

    protected function notes(): array
    {
        return [
            'Alur: Export -> hitung fisik -> isi STOK FISIK -> Import. Selisih dicatat di kartu stok bahan (jenis Opname), saldo tidak ditimpa langsung.',
            'Selisih dihitung dari saldo PADA WAKTU HITUNG. Bila ada pembelian/pemakaian bahan setelah file di-export, muncul peringatan.',
        ];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();

        if (! $row->filled('counted_qty')) {
            return; // tidak dihitung
        }

        $row->requireText(['warehouse' => 'KODE GUDANG', 'code' => 'KODE BAHAN']);
        $counted = $row->number('counted_qty', 'STOK FISIK', min: 0, required: true);
        $exported = $row->number('system_qty', 'STOK SISTEM');
        $countedAt = $row->countedAt('counted_at');
        $row->maxLength(['note' => ['CATATAN', 200]]);
        $row->throwIfInvalid();

        $warehouse = Warehouse::query()->whereRaw('lower(code) = ? or lower(name) = ?', [Str::lower($row->text('warehouse')), Str::lower($row->text('warehouse'))])->first()
            ?? throw new BusinessRuleException("gudang {$row->text('warehouse')} tidak ditemukan");

        if (! $warehouse->kind->storesMaterials()) {
            throw new BusinessRuleException("\"{$warehouse->name}\" adalah gudang barang jadi; opname bahan harus di gudang bahan");
        }

        $material = $this->findCi(Material::query(), 'code', $row->text('code'))
            ?? throw new BusinessRuleException("bahan {$row->text('code')} tidak ditemukan");
        $this->ledger->lockBalance('material', $material->id, $warehouse->id);
        $this->ledger->ensureNoLaterCount('material', $material->id, $warehouse->id, $countedAt);
        $current = $this->ledger->materialQtyAt($material->id, $warehouse->id, $countedAt);

        if ($exported !== null && round($exported, 3) !== round($current, 3)) {
            $this->warn("{$material->code}: saldo berubah sejak export ({$exported} -> {$current}); selisih dihitung pada waktu hitung fisik.");
        }

        $diff = round($counted - $current, 3);

        if ($diff == 0) {
            $this->bump('skipped', 'Bahan dihitung');

            return;
        }

        $this->ledger->moveMaterial(
            $material->id, $warehouse->id, $diff, MaterialMovementType::Opname,
            note: trim('Opname Excel '.$countedAt.' '.$row->text('note')), movedAt: $countedAt,
        );
        $this->bump('updated', 'Bahan dihitung');
    }

    protected function exportRows(): iterable
    {
        $warehouses = Warehouse::query()->forMaterials()->where('is_active', true)->orderBy('code')->get();
        $balances = MaterialBalance::query()->whereIn('warehouse_id', $warehouses->pluck('id'))->get()
            ->keyBy(fn (MaterialBalance $b): string => $b->warehouse_id.'|'.$b->material_id);
        $materials = Material::query()->orderBy('code')->get(['id', 'code', 'base_uom', 'is_active']);

        foreach ($warehouses as $warehouse) {
            foreach ($materials as $material) {
                $balance = $balances->get($warehouse->id.'|'.$material->id);

                if ($balance === null && ! $material->is_active) {
                    continue;
                }

                yield [$warehouse->code, $material->code, $material->base_uom->value, (float) ($balance?->qty ?? 0), null, null, null];
            }
        }
    }
}
