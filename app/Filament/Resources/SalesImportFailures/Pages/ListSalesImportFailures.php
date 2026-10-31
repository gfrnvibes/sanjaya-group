<?php

namespace App\Filament\Resources\SalesImportFailures\Pages;

use App\Enums\ImportFailureReason;
use App\Enums\ImportFailureStatus;
use App\Enums\SalesOrderStatus;
use App\Filament\Actions\BulkSkuMapping;
use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\SalesImportFailures\SalesImportFailureResource;
use App\Filament\Resources\SalesImportFailures\Widgets\ImportIssueOverview;
use App\Filament\Support\ImportResultNotification;
use App\Imports\ImportRegistry;
use App\Models\SalesImportFailure;
use App\Models\Warehouse;
use App\Services\SkuMapping;
use App\Services\SkuMatcher;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Import Perlu Dicek: 1 tab = 1 kategori data. Di tab kategori tersedia template perbaikan & import data yang benar.
 */
class ListSalesImportFailures extends ListRecords
{
    protected static string $resource = SalesImportFailureResource::class;

    public function mount(): void
    {
        parent::mount();

        $this->retryAlreadyMappedSkus();
    }

    /**
     * Auto-perbaiki: baris "SKU tidak ditemukan" yang SKU-nya sudah dipetakan di tempat lain (menu Pemetaan SKU,
     * import, katalog) langsung dicoba ulang saat halaman dibuka, agar tidak dipetakan dua kali.
     */
    private function retryAlreadyMappedSkus(): void
    {
        if (! ImportRegistry::allows(ImportRegistry::SALES_ORDERS)) {
            return;
        }

        $result = app(SkuMapping::class)->retryResolvable(SkuMapping::defaultOptions());

        if ($result['fixed'] === 0 && $result['failed'] === 0) {
            return;
        }

        Notification::make()
            ->title("{$result['fixed']} baris otomatis diperbaiki: SKU-nya sudah dipetakan")
            ->body(collect([
                $result['failed'] > 0 ? "{$result['failed']} baris masih gagal karena alasan lain (alasan terbaru ada di daftar)." : null,
                $result['remaining'] > 0 ? "{$result['remaining']} baris lagi diproses saat halaman dimuat ulang." : null,
            ])->filter()->implode(' ') ?: null)
            ->{$result['failed'] === 0 ? 'success' : 'warning'}()
            ->send();
    }

    public function getSubheading(): ?string
    {
        $open = SalesImportFailureResource::openCount();

        return $open === 0
            ? 'Semua data import sudah sesuai. Baris yang gagal dari import mana pun akan muncul di sini.'
            : "{$open} baris belum diperbaiki. Tangani per kategori: klik Edit pada baris untuk memperbaiki & mencoba ulang langsung, "
                .'atau unduh template kategori, perbaiki di Excel, lalu import data yang benar.';
    }

    protected function getHeaderWidgets(): array
    {
        return [ImportIssueOverview::class];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $open = SalesImportFailureResource::getEloquentQuery()
            ->where('status', ImportFailureStatus::Open)
            ->where('line', '>', 0)
            ->selectRaw('importer, count(*) as total')
            ->groupBy('importer')
            ->pluck('total', 'importer');
        $used = SalesImportFailureResource::getEloquentQuery()->distinct()->pluck('importer')->flip();

        $tabs = ['semua' => Tab::make('Semua')->badge($open->sum() ?: null)->badgeColor('danger')];

        foreach (ImportRegistry::accessibleKeys() as $key) {
            // Kategori tanpa riwayat gagal disembunyikan agar tab tetap ringkas (kecuali sedang dibuka).
            if (! isset($used[$key]) && ImportRegistry::fromTab($this->activeTab) !== $key) {
                continue;
            }

            $tabs[ImportRegistry::tab($key)] = Tab::make(ImportRegistry::label($key))
                ->badge($open[$key] ?? null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('importer', $key));
        }

        return $tabs;
    }

    public function activeCategory(): ?string
    {
        return ImportRegistry::fromTab($this->activeTab);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->bulkMapSkuAction(),
            $this->applySuggestionsAction(),
            Action::make('downloadCategoryTemplate')
                ->label(fn (): string => 'Unduh template '.ImportRegistry::label($this->activeCategory() ?? ''))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->visible(fn (): bool => $this->activeCategory() !== null)
                ->tooltip('Berisi baris yang sesuai filter tabel (default: belum diperbaiki) + ID GAGAL dan alasannya. Perbaiki kolom merah lalu import.')
                ->action(fn (): BinaryFileResponse => response()
                    ->download(
                        ImportRegistry::fixTemplate($this->activeCategory(), $this->getFilteredSortedTableQuery()),
                        'perbaikan-'.str($this->activeCategory())->replace('.', '-')->slug().'-'.now()->format('Ymd-His').'.xlsx',
                    )
                    ->deleteFileAfterSend()),
            Action::make('importCorrected')
                // Tiap baris impor disimpan dalam transaksinya sendiri; jangan dibungkus transaksi panel (ribuan baris = lock habis).
                ->databaseTransaction(false)
                ->label('Import data yang benar')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->visible(fn (): bool => $this->activeCategory() !== null && ImportRegistry::allows($this->activeCategory()))
                ->modalHeading(fn (): string => 'Import '.ImportRegistry::label($this->activeCategory() ?? '').' yang sudah diperbaiki')
                ->modalDescription('Gunakan template perbaikan kategori ini (atau template/export biasa). Baris berkolom ID GAGAL yang berhasil otomatis ditandai "Sudah diperbaiki"; yang masih gagal muncul lagi dengan alasan terbaru.')
                ->modalSubmitActionLabel('Import')
                ->schema(fn (): array => [
                    ResourceIo::fileUpload('imports/perbaikan'),
                    ...$this->categoryOptionFields($this->activeCategory()),
                ])
                ->action(function (array $data): void {
                    $key = $this->activeCategory();
                    $options = $this->categoryOptions($key, $data);
                    $result = ResourceIo::runWithUpload($data, fn (string $path, string $name): array => ImportRegistry::import($key, $path, $name, $options));

                    if ($key === ImportRegistry::SALES_ORDERS) {
                        $this->notifySales($result);

                        return;
                    }

                    ImportResultNotification::send(ImportRegistry::label($key), $result);
                }),
        ];
    }

    /**
     * Isian tambahan kategori saat import / coba ulang (pesanan: gudang & status; opname barang jadi: posting).
     *
     * @return list<Component>
     */
    public static function categoryOptionFields(?string $key): array
    {
        return match ($key) {
            ImportRegistry::SALES_ORDERS => [
                Select::make('warehouse_id')
                    ->label('Gudang asal')
                    ->options(fn (): array => Warehouse::goodsOptions())
                    ->default(fn () => Warehouse::defaultForGoods()?->id)
                    ->required(),
                Select::make('status')
                    ->label('Status pesanan')
                    ->options([
                        SalesOrderStatus::Completed->value => SalesOrderStatus::Completed->getLabel(),
                        SalesOrderStatus::Pending->value => SalesOrderStatus::Pending->getLabel(),
                    ])
                    ->default(SalesOrderStatus::Completed->value)
                    ->required(),
                DatePicker::make('order_date')
                    ->label('Tanggal cadangan')
                    ->helperText('Dipakai bila kolom TANGGAL kosong (file BigSeller).'),
            ],
            'goods_stock_count' => [
                Toggle::make('post')->label('Langsung posting (stok langsung disesuaikan)')->default(false),
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function categoryOptions(?string $key, array $data): array
    {
        return match ($key) {
            ImportRegistry::SALES_ORDERS => [
                'warehouse_id' => (int) ($data['warehouse_id'] ?? Warehouse::defaultForGoods()?->id),
                'status' => $data['status'] ?? SalesOrderStatus::Completed->value,
                'source' => 'manual',
                'order_date' => $data['order_date'] ?? null,
            ],
            'goods_stock_count' => ['post' => (bool) ($data['post'] ?? false)],
            default => [],
        };
    }

    /**
     * PETAKAN SKU MASSAL: 1 baris per SKU marketplace yang gagal, varian sudah terisi saran terbaik.
     * Baris yang dicentang disimpan sebagai Pemetaan SKU lalu semua pesanannya dicoba ulang sekaligus.
     */
    private function bulkMapSkuAction(): Action
    {
        return Action::make('bulkMapSku')
            // Tiap baris impor disimpan dalam transaksinya sendiri; jangan dibungkus transaksi panel (ribuan baris = lock habis).
            ->databaseTransaction(false)
            ->label('Petakan SKU massal')
            ->icon(Heroicon::OutlinedQueueList)
            ->color('primary')
            ->visible(fn (): bool => in_array($this->activeCategory(), [null, ImportRegistry::SALES_ORDERS], true)
                && ImportRegistry::allows(ImportRegistry::SALES_ORDERS)
                && app(SkuMapping::class)->openSkuFailures()->exists())
            ->modalHeading('Petakan SKU massal')
            ->modalDescription('1 baris = 1 SKU marketplace yang belum dikenal. Varian sudah terisi saran sistem (kode model, warna, ukuran, nama produk & variasi); ubah yang salah, centang yang mau disimpan (atau Pilih semua). Pemetaan masuk menu Pemetaan SKU lalu semua pesanan dengan SKU tersebut dicoba ulang.')
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitActionLabel('Simpan pemetaan & coba ulang')
            ->fillForm(fn (): array => BulkSkuMapping::fill())
            ->schema(fn (): array => BulkSkuMapping::schema())
            ->action(fn (array $data) => BulkSkuMapping::handle($data));
    }

    private function applySuggestionsAction(): Action
    {
        return Action::make('applySkuSuggestions')
            // Tiap baris impor disimpan dalam transaksinya sendiri; jangan dibungkus transaksi panel (ribuan baris = lock habis).
            ->databaseTransaction(false)
            ->label('Terapkan saran SKU')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('primary')
            ->visible(fn (): bool => in_array($this->activeCategory(), [null, ImportRegistry::SALES_ORDERS], true)
                && ImportRegistry::allows(ImportRegistry::SALES_ORDERS)
                && SalesImportFailure::query()->open()->where('importer', ImportRegistry::SALES_ORDERS)->where('reason', ImportFailureReason::SkuNotFound)->exists())
            ->modalHeading('Petakan SKU otomatis dari saran sistem')
            ->modalDescription(function (SkuMapping $mapping): string {
                $plan = $mapping->plan();

                return $plan === []
                    ? 'Belum ada SKU dengan saran yang cukup yakin. Petakan manual lewat tombol Petakan SKU pada baris.'
                    : count($plan).' SKU marketplace akan dipetakan (skor >= '.SkuMatcher::HIGH_CONFIDENCE.'%, berdasarkan kode model, warna, ukuran & nama produk): '
                        .collect($plan)->take(8)->map(fn (array $item): string => "{$item['alias']} → {$item['variant']->sku} ({$item['score']}%)")->implode('; ')
                        .(count($plan) > 8 ? '; …' : '').'. Pemetaan disimpan di menu Pemetaan SKU lalu semua baris dengan SKU tersebut dicoba ulang.';
            })
            ->schema(fn (): array => self::categoryOptionFields(ImportRegistry::SALES_ORDERS))
            ->modalSubmitActionLabel('Petakan & coba ulang')
            ->action(function (array $data, SkuMapping $mapping): void {
                $result = $mapping->applyPlan(self::categoryOptions(ImportRegistry::SALES_ORDERS, $data));

                Notification::make()
                    ->title("{$result['mapped']} SKU dipetakan, {$result['fixed']} baris berhasil disimpan")
                    ->body($result['errors'] === [] ? null : e(implode("\n", array_slice($result['errors'], 0, 10))))
                    ->{$result['errors'] === [] ? 'success' : 'warning'}()
                    ->send();
            });
    }

    /**
     * @param  array{created: int, skipped: int, fixed: int, errors: list<string>}  $result
     */
    private function notifySales(array $result): void
    {
        Notification::make()
            ->title("{$result['created']} pesanan tersimpan, {$result['fixed']} baris gagal kini sudah diperbaiki")
            ->body($result['errors'] === [] ? null : count($result['errors']).' baris masih gagal dan muncul lagi di daftar.')
            ->{$result['errors'] === [] ? 'success' : 'warning'}()
            ->send();
    }
}
