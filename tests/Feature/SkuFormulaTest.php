<?php

use App\Exceptions\BusinessRuleException;
use App\Models\Color;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Services\CatalogImporter;
use App\Services\CatalogSpreadsheet;

beforeEach(function () {
    $this->f = businessFixture();
    $this->model = ProductModel::create(['code' => 'BC1', 'name' => 'Baju Chef', 'category_id' => $this->f['category']->id, 'kind' => 'produksi']);
    $this->black = Color::create(['code' => 'H', 'name' => 'Hitam']);
    $this->small = Size::create(['code' => 'S', 'sort_order' => 1]);
});

it('fills an empty SKU from model code, colour code and size', function () {
    $variant = ProductVariant::create(['model_id' => $this->model->id, 'color_id' => $this->black->id, 'size_id' => $this->small->id, 'name' => 'Baju Chef Hitam S']);

    expect($variant->sku)->toBe('BC1HS')
        ->and(fn () => ProductVariant::create(['model_id' => $this->model->id, 'color_id' => $this->black->id, 'size_id' => $this->small->id, 'name' => 'Dobel']))
        ->toThrow(BusinessRuleException::class, 'BC1HS');
});

it('creates catalog variants with the SKU formula when SKU VARIAN is empty and matches them again on re-import', function () {
    $path = tempnam(sys_get_temp_dir(), 'sku-').'.csv';
    file_put_contents($path, "KODE MODEL,KODE WARNA,NAMA WARNA,UKURAN,SKU VARIAN\nBC1,H,Hitam,S,\nBC1,BGE,Beige,S,\n");

    try {
        app(CatalogImporter::class)->import($path, 'produk.csv', ['products']);
        app(CatalogImporter::class)->import($path, 'produk.csv', ['products']);
    } finally {
        @unlink($path);
    }

    expect(ProductVariant::where('model_id', $this->model->id)->orderBy('sku')->pluck('sku')->all())->toBe(['BC1BGES', 'BC1HS'])
        ->and(Color::where('code', 'BGE')->value('name'))->toBe('Beige');
});

it('exports the SKU as a formula and keeps SKUs when the file is re-imported before Excel recalculates it', function () {
    $variant = ProductVariant::create(['model_id' => $this->model->id, 'color_id' => $this->black->id, 'size_id' => $this->small->id, 'name' => 'Baju Chef Hitam S']);
    $path = app(CatalogSpreadsheet::class)->export('xlsx', 'products');

    try {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        // Varian fixture (SKU 001234) tidak sesuai rumus: tetap teks biasa.
        expect($xml)->toMatch('/<f>B\d+&amp;F\d+&amp;H\d+<\/f>/')
            ->and(substr_count($xml, '<f>'))->toBe(1);

        app(CatalogImporter::class)->import($path, 'katalog.xlsx', ['products']);
    } finally {
        @unlink($path);
    }

    expect($variant->fresh()->sku)->toBe('BC1HS')
        ->and($this->f['variant']->fresh()->sku)->toBe('001234');
});
