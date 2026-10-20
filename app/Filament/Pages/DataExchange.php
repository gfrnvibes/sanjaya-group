<?php

namespace App\Filament\Pages;

use App\Filament\Actions\CatalogActions;
use App\Filament\Actions\ResourceIo;
use App\Filament\Actions\StoreActions;
use App\Imports\GoodsStockCountImport;
use App\Imports\MaterialStockCountImport;
use App\Services\CatalogImporter;
use App\Services\CatalogSpreadsheet;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * PUSAT IMPORT / EXPORT: alur "Export -> edit di Excel -> Import ulang" untuk data master & stok.
 * Satu file per jenis data. Transaksi (pesanan, pembelian, pembayaran) tidak diedit lewat sini.
 */
class DataExchange extends Page
{
    protected static string $routePath = 'import-export';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Import / Export';

    protected static ?string $title = 'Pusat Import / Export';

    public function getSubheading(): ?string
    {
        return 'Ubah data massal: Export → edit di Excel → Import kembali file yang sama.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cara pakai')
                ->icon(Heroicon::OutlinedInformationCircle)
                ->collapsible()
                ->schema([
                    Text::make('1. Klik Export pada jenis data yang ingin diubah. 2. Edit di Excel: kolom header MERAH boleh diubah, kolom HIJAU (ID, stok sistem, DIUBAH TERAKHIR, HPP/harga otomatis) hanya info — jangan diubah. 3. Import file tersebut. Baris valid disimpan, baris error dilaporkan per nomor baris.'),
                    Text::make('Ganti nama / kode / SKU: ubah teksnya, biarkan kolom ID. Menonaktifkan data: isi AKTIF = Tidak (menghapus baris di Excel TIDAK menghapus data). Data baru: tambah baris dengan kolom ID dikosongkan.'),
                    Text::make('Bila data diubah orang lain setelah Anda export, muncul peringatan — isi file Anda tetap dipakai. Stok tidak pernah ditimpa: selisih dengan hasil hitung dicatat sebagai opname di kartu stok. Transaksi (pesanan, pembelian, pembayaran) diinput lewat menunya masing-masing.'),
                ]),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('Reseller & Toko')
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->description('Pemilik, reseller, toko, channel, dan tier dalam 1 sheet (1 baris = 1 toko). Pindah toko ke reseller lain tidak bisa lewat Excel.')
                    ->schema([
                        Actions::make([
                            StoreActions::exportAction()->label('Export Reseller & Toko')->button(),
                            StoreActions::importAction()->label('Import')->button()->color('gray'),
                            StoreActions::templateAction()->label('Template kosong')->link(),
                        ]),
                    ]),
                Section::make('Produk, Bahan & HPP')
                    ->icon(Heroicon::OutlinedSwatch)
                    ->description('Workbook sheet Bahan, Produk, Resep BOM, Pembelian Bahan. HPP & harga reseller dihitung otomatis dari resep + margin.')
                    ->schema([
                        Actions::make([
                            CatalogActions::exportAction('exportCatalog_xlsx', 'Export Katalog', 'xlsx')->button(),
                            CatalogActions::importAction()->label('Import')->button()->color('gray'),
                            ActionGroup::make([
                                CatalogActions::templateAction()->label('Template kosong'),
                                ...collect(CatalogSpreadsheet::FORMATS)
                                    ->except('xlsx')
                                    ->map(fn (string $label, string $format): Action => CatalogActions::exportAction("exportCatalog_{$format}", "Export katalog – {$label}", $format))
                                    ->values()
                                    ->all(),
                                ...collect(CatalogImporter::SHEETS)
                                    ->map(fn (array $sheet, string $type): Action => CatalogActions::exportAction("exportCatalog_csv_{$type}", "Export CSV {$sheet['title']}", 'csv', $type))
                                    ->values()
                                    ->all(),
                            ])->label('Lainnya')->link()->icon(Heroicon::OutlinedEllipsisHorizontal),
                        ]),
                    ]),
                Section::make('Stok Opname Barang Jadi')
                    ->icon(Heroicon::OutlinedCube)
                    ->description('Export stok sistem per gudang barang jadi, isi STOK FISIK hasil hitung, lalu import. Disimpan sebagai dokumen Stok Opname (draft untuk diperiksa, atau langsung diposting).')
                    ->schema([
                        Actions::make([
                            ResourceIo::exportDataAction(GoodsStockCountImport::class, 'goodsCount')->label('Export Stok Barang Jadi')->button(),
                            ResourceIo::importAction(GoodsStockCountImport::class, 'goodsCount', [
                                Toggle::make('post')
                                    ->label('Langsung posting (stok langsung disesuaikan)')
                                    ->helperText('Matikan untuk menyimpan sebagai draft dan memeriksa selisihnya dulu di menu Stok Opname.')
                                    ->default(false),
                            ])->label('Import Hasil Hitung')->button()->color('gray'),
                        ]),
                    ]),
                Section::make('Stok Opname Bahan')
                    ->icon(Heroicon::OutlinedRectangleStack)
                    ->description('Export saldo bahan per gudang bahan, isi STOK FISIK (satuan dasar), lalu import. Selisih dicatat di kartu stok bahan sebagai opname; impor ulang file yang sama tidak menggandakan.')
                    ->schema([
                        Actions::make([
                            ResourceIo::exportDataAction(MaterialStockCountImport::class, 'materialCount')->label('Export Stok Bahan')->button(),
                            ResourceIo::importAction(MaterialStockCountImport::class, 'materialCount')->label('Import Hasil Hitung')->button()->color('gray'),
                        ]),
                    ]),
            ]),
        ]);
    }
}
