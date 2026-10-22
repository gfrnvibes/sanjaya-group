<?php

use App\Models\Bom;
use App\Models\Channel;
use App\Models\Material;
use App\Models\MaterialType;
use App\Models\Owner;
use App\Models\ProductCategory;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\Reseller;
use App\Models\ResellerPriceHistory;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\VariantCost;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\SalesOrderService;

/** @return array<string, mixed> */
function businessFixture(int $stock = 100): array
{
    $owner = Owner::create(['code' => 'OWN', 'name' => 'Owner', 'is_active' => true]);
    $reseller = Reseller::create(['owner_id' => $owner->id, 'name' => 'Reseller', 'opening_balance' => 0, 'is_active' => true]);
    $channel = Channel::create(['code' => 'SHOP', 'name' => 'Shop', 'kind' => 'marketplace']);
    $store = Store::create(['reseller_id' => $reseller->id, 'channel_id' => $channel->id, 'name' => 'Shop', 'is_active' => true]);
    $warehouse = Warehouse::create(['code' => 'GOODS', 'name' => 'Goods', 'kind' => 'barang_jadi', 'is_active' => true]);
    $materialWarehouse = Warehouse::create(['code' => 'MAT', 'name' => 'Materials', 'kind' => 'bahan', 'is_active' => true]);
    $category = ProductCategory::create(['name' => 'Clothes']);
    $model = ProductModel::create(['code' => 'MODEL', 'name' => 'Model', 'category_id' => $category->id, 'kind' => 'produksi']);
    $variant = ProductVariant::create(['model_id' => $model->id, 'sku' => '001234', 'name' => 'Variant', 'is_active' => true]);
    $cost = VariantCost::create(['variant_id' => $variant->id, 'effective_from' => '2026-01-01', 'material_cost' => 60, 'labor_cost' => 0, 'source' => 'manual']);
    ResellerPriceHistory::create(['variant_id' => $variant->id, 'effective_from' => '2026-01-01', 'base_price' => 100, 'variant_cost_id' => $cost->id, 'source' => 'manual']);
    $materialType = MaterialType::create(['code' => 'FAB', 'name' => 'Fabric', 'kind' => 'kain']);
    $material = Material::create(['material_type_id' => $materialType->id, 'code' => 'FAB', 'base_uom' => 'cm', 'qty_per_pack' => 1000, 'is_active' => true]);
    $bom = Bom::create(['model_id' => $model->id, 'effective_from' => '2026-01-01']);
    $bom->items()->create(['line_type' => 'material', 'material_type_id' => $materialType->id, 'color_mode' => 'fixed', 'material_id' => $material->id, 'qty_per_unit' => 1]);
    app(InventoryService::class)->openingMaterial($material->id, $materialWarehouse->id, 1000, 60, '2026-01-01');
    if ($stock > 0) {
        app(InventoryService::class)->openingStock($variant->id, $warehouse->id, $stock, 60, '2026-01-01');
    }

    return compact('owner', 'reseller', 'channel', 'store', 'warehouse', 'materialWarehouse', 'category', 'model', 'variant', 'cost', 'material', 'bom');
}

/** @param array<string,mixed> $fixture */
function fixtureOrder(array $fixture, int $qty = 10, string $date = '2026-10-01'): SalesOrder
{
    return app(SalesOrderService::class)->create(['store_id' => $fixture['store']->id, 'warehouse_id' => $fixture['warehouse']->id, 'order_date' => $date], [['variant_id' => $fixture['variant']->id, 'qty' => $qty, 'revenue' => $qty * 150]]);
}
