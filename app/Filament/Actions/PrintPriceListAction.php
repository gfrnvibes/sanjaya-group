<?php

namespace App\Filament\Actions;

use App\Enums\ProductKind;
use App\Models\PriceTier;
use App\Models\ProductCategory;
use App\Models\ProductModel;
use App\Services\ResellerPriceList;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Cetak / PDF DAFTAR HARGA RESELLER per tier (tanpa HPP & margin), siap dibagikan ke reseller.
 * Di baris tabel Tier Harga ($forRecord) tier mengikuti baris; selain itu pilih satu / beberapa tier.
 */
class PrintPriceListAction
{
    public static function make(string $name = 'printPriceList', bool $forRecord = false): Action
    {
        return Action::make($name)
            ->label($forRecord ? 'Cetak harga' : 'Cetak Daftar Harga per Tier')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->modalHeading(fn (?PriceTier $record): string => $record ? 'Cetak daftar harga '.ResellerPriceList::tierLabel($record) : 'Cetak daftar harga reseller per tier')
            ->modalDescription('Harga = harga dasar berlaku x (1 + % tier), dibulatkan sama seperti harga pesanan. HPP & margin tidak ikut dicetak. Halaman cetak terbuka di tab baru: pilih "Simpan sebagai PDF".')
            ->modalSubmitActionLabel('Cetak / PDF')
            ->fillForm(fn (): array => ['date' => today()->toDateString(), 'layout' => ResellerPriceList::BY_SIZE])
            ->schema(fn (?PriceTier $record): array => [
                Select::make('tier_ids')
                    ->label('Tier')
                    ->options(fn (): array => PriceTier::query()->orderBy('adjustment_pct')->orderBy('code')->get()
                        ->mapWithKeys(fn (PriceTier $tier): array => [$tier->id => ResellerPriceList::tierLabel($tier)])->all())
                    ->multiple()
                    ->placeholder('Semua tier (1 kolom per tier)')
                    ->helperText('Pilih 1 tier untuk dikirim ke reseller tier tersebut; beberapa tier = kolom harga berdampingan.')
                    ->hidden($record !== null),
                DatePicker::make('date')
                    ->label('Harga berlaku pada')
                    ->required(),
                Radio::make('layout')
                    ->label('Tampilan')
                    ->options(ResellerPriceList::LAYOUTS)
                    ->descriptions([
                        ResellerPriceList::BY_SIZE => 'Satu baris per model (kode + nama), harga tiap ukuran berjajar ke samping seperti tabel Excel.',
                        ResellerPriceList::BY_MODEL => 'Satu baris per model, ukuran berharga sama digabung (mis. S–XL).',
                        ResellerPriceList::BY_SKU => 'Semua SKU satu per satu (warna, ukuran) dikelompokkan per model.',
                    ])
                    ->required(),
                Select::make('kind')
                    ->label('Jenis produk')
                    ->options(ProductKind::class)
                    ->placeholder('Semua jenis'),
                Select::make('category_ids')
                    ->label('Kategori')
                    ->options(fn (): array => ProductCategory::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->multiple()
                    ->placeholder('Semua kategori'),
                Select::make('model_ids')
                    ->label('Model')
                    ->options(fn (): array => ProductModel::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->multiple()
                    ->searchable()
                    ->placeholder('Semua model'),
                Textarea::make('note')
                    ->label('Catatan di bawah daftar (opsional)')
                    ->placeholder('Mis. Harga dapat berubah sewaktu-waktu. Pemesanan via WA 08xx.')
                    ->rows(2),
            ])
            ->action(function (array $data, Component $livewire, ResellerPriceList $priceList, ?PriceTier $record): void {
                $kind = $data['kind'] ?? null;

                ResourceIo::openPrint($livewire, $priceList->html(
                    $record ? [$record->id] : array_map('intval', $data['tier_ids'] ?? []),
                    Carbon::parse($data['date']),
                    [
                        'kind' => $kind instanceof ProductKind ? $kind->value : $kind,
                        'category_ids' => array_map('intval', $data['category_ids'] ?? []),
                        'model_ids' => array_map('intval', $data['model_ids'] ?? []),
                    ],
                    $data['note'] ?? null,
                    $data['layout'] ?? ResellerPriceList::BY_SIZE,
                ));
            });
    }
}
