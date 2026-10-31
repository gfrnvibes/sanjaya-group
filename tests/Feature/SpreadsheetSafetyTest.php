<?php

use App\Exceptions\BusinessRuleException;
use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\ProductVariants\Pages\ListProductVariants;
use App\Imports\SkuAliasImport;
use App\Models\SkuAlias;
use App\Models\User;
use App\Services\CatalogSpreadsheet;
use App\Support\Spreadsheet;
use App\Support\TableExporter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('exports aliases as literal spreadsheet text while retaining leading zero SKUs', function () {
    $f = businessFixture();
    SkuAlias::create(['alias' => '=1+1', 'variant_id' => $f['variant']->id, 'source' => 'bigseller']);
    $path = app(SkuAliasImport::class)->export();
    try {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        expect($xml)->toContain('=1+1')->toContain('001234')->not->toContain('<f>');
        $rows = Spreadsheet::read($path, 'aliases.xlsx', ['ALIAS' => 'alias', 'SKU VARIAN' => 'sku'], ['alias', 'sku'], 'Missing headers');
        expect($rows[0]['values']['alias'])->toBe('=1+1');
        expect($rows[0]['values']['sku'])->toBe('001234');
    } finally {
        gc_collect_cycles();
        @unlink($path);
    }
});

it('exports numeric looking SKU strings without stripping leading zeroes from a resource table', function () {
    $f = businessFixture();
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);
    $component = Livewire::test(ListProductVariants::class);
    $path = app(TableExporter::class)->export($component->instance(), 'xlsx', 'Products');
    try {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        expect($xml)->toContain('001234');
        $rows = Spreadsheet::read($path, 'products.xlsx', ['SKU' => 'sku', 'HPP' => 'hpp'], ['sku', 'hpp'], 'Missing headers');
        expect($rows[0]['values']['sku'])->toBe('001234');
        expect(is_int($rows[0]['values']['hpp']) || is_float($rows[0]['values']['hpp']))->toBeTrue();
        expect($rows[0]['values']['hpp'])->toEqual(60);
    } finally {
        @unlink($path);
    }
});

it('neutralizes formula text in CSV exports', function () {
    $f = businessFixture();
    $f['variant']->update(['name' => '=1+1']);
    $path = app(CatalogSpreadsheet::class)->export('csv', 'products');
    try {
        expect(file_get_contents($path))->toContain("'=1+1");
    } finally {
        @unlink($path);
    }
});

it('rejects oversized imports before saving any aliases', function () {
    config(['business.import_max_rows' => 1]);
    $path = tempnam(sys_get_temp_dir(), 'alias-');
    file_put_contents($path, "Alias,SKU VARIAN\nA,001234\nB,001234\n");
    try {
        $result = app(SkuAliasImport::class)->import($path, 'aliases.csv');
        expect($result['errors'][0])->toContain('melebihi batas');
        $this->assertDatabaseCount('sku_aliases', 0);
    } finally {
        unlink($path);
    }
});

it('reports a corrupt XLSX as a readable import error without saving data', function () {
    $path = tempnam(sys_get_temp_dir(), 'broken-');
    file_put_contents($path, 'this is not a ZIP workbook');
    try {
        $result = app(SkuAliasImport::class)->import($path, 'broken.xlsx');
        expect($result['errors'][0])->toContain('rusak atau tidak dapat dibaca');
        $this->assertDatabaseCount('sku_aliases', 0);
    } finally {
        unlink($path);
    }
});

it('rejects forged import paths without reading or deleting private files', function () {
    $disk = Storage::fake('import-paths-'.Str::uuid());
    Storage::set('local', $disk);
    $disk->put('backups/secret.csv', 'sensitive');
    $disk->put('imports/valid.csv', 'Alias,SKU VARIAN');

    foreach (['backups/secret.csv', 'imports/../backups/secret.csv', 'imports/..\\backups\\secret.csv'] as $path) {
        expect(fn () => ResourceIo::runWithUpload(['file' => $path], fn () => throw new LogicException('Importer must not run')))
            ->toThrow(BusinessRuleException::class);
        $disk->assertExists('backups/secret.csv');
    }

    expect(ResourceIo::runWithUpload(['file' => 'imports/valid.csv'], fn (string $path) => file_get_contents($path)))
        ->toBe('Alias,SKU VARIAN');
    $disk->assertMissing('imports/valid.csv');
});
