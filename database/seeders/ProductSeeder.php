<?php

namespace Database\Seeders;

use App\Enums\BaseUom;
use App\Enums\MaterialKind;
use App\Enums\ProductKind;
use App\Models\Color;
use App\Models\Material;
use App\Models\MaterialType;
use App\Models\ProductCategory;
use App\Models\ProductModel;
use App\Models\Size;
use Illuminate\Database\Seeder;

/**
 * Warna, ukuran, kategori, model, varian (SKU), pemetaan SKU, jenis bahan & bahan.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $colors = collect([['H', 'Hitam'], ['P', 'Putih'], ['N', 'Navy']])
            ->mapWithKeys(fn (array $color) => [$color[0] => Color::create(['code' => $color[0], 'name' => $color[1]])]);

        $sizes = collect(['S', 'M', 'L', 'XL'])
            ->mapWithKeys(fn (string $code, int $index) => [$code => Size::create(['code' => $code, 'sort_order' => $index + 1])]);

        $chef = ProductCategory::create(['name' => 'Baju Chef']);
        $apron = ProductCategory::create(['name' => 'Apron']);
        $hat = ProductCategory::create(['name' => 'Topi']);

        $models = [
            [['code' => 'BC1', 'name' => 'Baju Chef 01', 'category_id' => $chef->id, 'kind' => ProductKind::Produksi], ['H', 'P', 'N'], ['S', 'M', 'L', 'XL'], 10],
            [['code' => 'AP1', 'name' => 'Apron Kanvas', 'category_id' => $apron->id, 'kind' => ProductKind::Produksi, 'default_margin_pct' => 0.35], ['H', 'N'], [], 8],
            [['code' => 'TC1', 'name' => 'Topi Chef', 'category_id' => $hat->id, 'kind' => ProductKind::BeliJadi, 'default_margin_pct' => 0.40], ['P'], [], 15],
        ];

        foreach ($models as [$attributes, $colorCodes, $sizeCodes, $minStock]) {
            $model = ProductModel::create($attributes);

            foreach ($colorCodes as $colorCode) {
                foreach ($sizeCodes ?: [null] as $sizeCode) {
                    $color = $colors[$colorCode];
                    $size = $sizeCode ? $sizes[$sizeCode] : null;

                    $variant = $model->variants()->create([
                        'color_id' => $color->id,
                        'size_id' => $size?->id,
                        'sku' => $model->code.$color->code.$size?->code,
                        'name' => trim("{$model->name} {$color->name} {$size?->code}"),
                        'min_stock' => $minStock,
                        'margin_pct' => $sizeCode === 'XL' ? 0.28 : null,
                    ]);

                    $variant->skuAliases()->create(['alias' => 'BS-'.$variant->sku, 'source' => 'bigseller']);
                }
            }
        }

        $this->seedMaterials($colors->all());
    }

    /**
     * @param  array<string, Color>  $colors
     */
    private function seedMaterials(array $colors): void
    {
        $drill = MaterialType::create(['code' => 'DRILL', 'name' => 'Kain Drill', 'kind' => MaterialKind::Kain]);
        $canvas = MaterialType::create(['code' => 'KANVAS', 'name' => 'Kain Kanvas', 'kind' => MaterialKind::Kain]);
        $button = MaterialType::create(['code' => 'KANCING', 'name' => 'Kancing Chef', 'kind' => MaterialKind::Aksesoris]);
        $strap = MaterialType::create(['code' => 'TALI', 'name' => 'Tali Webbing', 'kind' => MaterialKind::Aksesoris]);

        foreach (['H', 'P', 'N'] as $code) {
            Material::create([
                'material_type_id' => $drill->id,
                'color_id' => $colors[$code]->id,
                'code' => 'DRILL - '.strtoupper($colors[$code]->name),
                'base_uom' => BaseUom::Cm,
                'pack_uom' => 'roll',
                'qty_per_pack' => 3000,
                'min_stock' => 6000,
            ]);
        }

        foreach (['H', 'N'] as $code) {
            Material::create([
                'material_type_id' => $canvas->id,
                'color_id' => $colors[$code]->id,
                'code' => 'KANVAS - '.strtoupper($colors[$code]->name),
                'base_uom' => BaseUom::Cm,
                'pack_uom' => 'roll',
                'qty_per_pack' => 2500,
                'min_stock' => 2500,
            ]);
        }

        Material::create(['material_type_id' => $button->id, 'code' => 'KANCING CHEF', 'base_uom' => BaseUom::Pcs, 'pack_uom' => 'pak', 'qty_per_pack' => 144, 'min_stock' => 288]);
        Material::create(['material_type_id' => $strap->id, 'code' => 'TALI WEBBING', 'base_uom' => BaseUom::Cm, 'pack_uom' => 'roll', 'qty_per_pack' => 10000, 'min_stock' => 5000]);
    }
}
