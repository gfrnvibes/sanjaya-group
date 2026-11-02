<?php

namespace Database\Seeders;

use App\Enums\ChannelKind;
use App\Models\CashAccount;
use App\Models\Channel;
use App\Models\ExpenseCategory;
use App\Models\LaborType;
use App\Models\Owner;
use App\Models\PriceTier;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Tailor;
use App\Models\Warehouse;
use App\Support\ApplicationAccess;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Pengaturan, peran, channel, tier, pemilik/reseller/toko, gudang, kas, supplier, penjahit.
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSettings();
        $this->seedRoles();
        $this->seedChannels();
        $this->seedOwners();

        foreach ([['UTAMA', 'Gudang Utama'], ['SHOWROOM', 'Showroom Konveksi']] as [$code, $name]) {
            Warehouse::create(['code' => $code, 'name' => $name]);
        }

        CashAccount::create(['name' => 'BCA Konveksi', 'bank' => 'BCA', 'holder' => 'Sanjaya Konveksi']);
        CashAccount::create(['name' => 'Kas Tunai', 'bank' => null, 'holder' => 'Admin Konveksi']);

        foreach ([
            'LISTRIK_AIR' => 'Listrik & Air',
            'SEWA' => 'Sewa Tempat',
            'GAJI_STAF' => 'Gaji Staf Konveksi',
            'GAJI_ADMIN_TOKO' => 'Gaji Admin Toko',
            'PACKING' => 'Packing',
            'ONGKIR' => 'Ongkos Kirim',
        ] as $code => $name) {
            ExpenseCategory::create(['code' => $code, 'name' => $name]);
        }

        Supplier::create(['name' => 'Toko Kain Sumber Rejeki', 'phone' => '081234500001']);
        Supplier::create(['name' => 'CV Aksesoris Jaya', 'phone' => '081234500002']);
        Supplier::create(['name' => 'Mitra Topi Bandung', 'phone' => '081234500003']);
        Supplier::create(['name' => 'Borongan Bu Tini', 'phone' => '081234500004']);

        foreach ([['POTONG', 'Potong'], ['JAHIT', 'Jahit'], ['OBRAS', 'Obras'], ['BORDIR', 'Bordir']] as [$code, $name]) {
            LaborType::create(['code' => $code, 'name' => $name]);
        }

        Tailor::create(['name' => 'Pak Joko', 'phone' => '085700000001']);
        Tailor::create(['name' => 'Bu Sri', 'phone' => '085700000002']);
        Tailor::create(['name' => 'Pak Darto', 'phone' => '085700000003', 'is_active' => false]);
    }

    private function seedSettings(): void
    {
        foreach ([
            'margin_default' => 0.30,
            'margin_ready_made_pct' => 0.05,
            'packing_cost_default' => 2000,
            'price_rounding' => 500,
            'tier_price_rounding' => 100,
            'price_tolerance_pct' => 0.02,
            'analysis_days' => 30,
            'target_stock_days' => 30,
            'lead_time_days' => 7,
            'production_batch' => 6,
        ] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    private function seedRoles(): void
    {
        $permissions = collect(ApplicationAccess::PERMISSIONS)
            ->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        // super_admin (Filament Shield) = semua akses; peran lain diatur lewat menu Peran (izin per resource/halaman).
        Role::findOrCreate(ApplicationAccess::superAdminRole(), 'web')->syncPermissions($permissions);
        Role::findOrCreate('gudang', 'web')->syncPermissions(['kelola stok', 'kelola produksi']);
        Role::findOrCreate('keuangan', 'web')->syncPermissions(['kelola keuangan', 'lihat laporan']);
        Role::findOrCreate('reseller', 'web')->syncPermissions(['lihat laporan']);
    }

    private function seedChannels(): void
    {
        $channels = [
            ['SHOPEE', 'Shopee', ChannelKind::Marketplace, [['2026-06-01', 0.08, 0.05], ['2026-09-01', 0.10, 0.05]]],
            ['TIKTOK', 'TikTok Shop', ChannelKind::Marketplace, [['2026-06-01', 0.06, 0.04]]],
            ['LAZADA', 'Lazada', ChannelKind::Marketplace, [['2026-06-01', 0, 0]]],
            ['WA', 'WhatsApp', ChannelKind::Chat, [['2026-06-01', 0, 0]]],
            ['OFFLINE', 'Offline / Datang Langsung', ChannelKind::Offline, [['2026-06-01', 0, 0]]],
        ];

        foreach ($channels as [$code, $name, $kind, $rates]) {
            $channel = Channel::create(['code' => $code, 'name' => $name, 'kind' => $kind]);

            foreach ($rates as [$from, $admin, $ads]) {
                $channel->feeRates()->create(['effective_from' => $from, 'admin_fee_pct' => $admin, 'ads_pct' => $ads]);
            }
        }
    }

    private function seedOwners(): void
    {
        $tiers = collect([
            ['T1', 'Tier 1', 0],
            ['T2', 'Tier 2', 0.05],
            ['T3', 'Tier 3', 0.10],
        ])->mapWithKeys(fn (array $tier) => [$tier[0] => PriceTier::create(['code' => $tier[0], 'name' => $tier[1], 'adjustment_pct' => $tier[2]])]);

        $channels = Channel::query()->pluck('id', 'code');

        $owners = [
            [
                'owner' => ['code' => 'ARR', 'name' => 'Arief Rahman', 'phone' => '081311110001'],
                'tiers' => [['2026-06-01', 'T1'], ['2026-09-01', 'T2']],
                'resellers' => [
                    ['name' => 'ARR SANJAYA', 'opening_balance' => 1250000, 'stores' => ['SHOPEE', 'TIKTOK', 'WA']],
                ],
            ],
            [
                'owner' => ['code' => 'DWI', 'name' => 'Dwi Lestari', 'phone' => '081311110002'],
                'tiers' => [['2026-06-01', 'T2']],
                'resellers' => [
                    ['name' => 'DAPUR DWI', 'opening_balance' => 0, 'stores' => ['SHOPEE', 'OFFLINE']],
                ],
            ],
            [
                'owner' => ['code' => 'BMS', 'name' => 'Budi Santoso', 'phone' => '081311110003'],
                'tiers' => [['2026-06-01', 'T3']],
                'resellers' => [
                    ['name' => 'BMS CHEF', 'opening_balance' => 450000, 'stores' => ['SHOPEE']],
                    ['name' => 'BMS UNIFORM', 'opening_balance' => 0, 'stores' => ['WA']],
                ],
            ],
        ];

        foreach ($owners as $data) {
            $owner = Owner::create($data['owner']);

            foreach ($data['tiers'] as [$from, $tierCode]) {
                $owner->tierHistories()->create(['price_tier_id' => $tiers[$tierCode]->id, 'effective_from' => $from]);
            }

            foreach ($data['resellers'] as $resellerData) {
                $reseller = $owner->resellers()->create([
                    'name' => $resellerData['name'],
                    'opening_balance' => $resellerData['opening_balance'],
                    'opening_balance_date' => '2026-06-01',
                ]);

                foreach ($resellerData['stores'] as $channelCode) {
                    $reseller->stores()->create([
                        'channel_id' => $channels[$channelCode],
                        'name' => $reseller->name.' '.str($channelCode)->lower()->ucfirst()->replace('Wa', 'WA'),
                        'external_ref' => in_array($channelCode, ['SHOPEE', 'TIKTOK'], true) ? strtolower($channelCode).'-'.str($reseller->name)->slug() : null,
                    ]);
                }
            }
        }
    }
}
