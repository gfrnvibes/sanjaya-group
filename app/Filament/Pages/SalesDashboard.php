<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\SalesDashboard\DeadStockTable;
use App\Filament\Widgets\SalesDashboard\MarginTable;
use App\Filament\Widgets\SalesDashboard\MaterialNeedsTable;
use App\Filament\Widgets\SalesDashboard\ProductionPriorityTable;
use App\Filament\Widgets\SalesDashboard\ReturnModelsTable;
use App\Filament\Widgets\SalesDashboard\ReturnReasonsTable;
use App\Filament\Widgets\SalesDashboard\ReturnStats;
use App\Filament\Widgets\SalesDashboard\ReturnStoresTable;
use App\Filament\Widgets\SalesDashboard\ReturnVariantsTable;
use App\Filament\Widgets\SalesDashboard\SummaryStats;
use App\Filament\Widgets\SalesDashboard\TopModelsTable;
use App\Filament\Widgets\SalesDashboard\TopVariantsTable;
use App\Models\Channel;
use App\Models\ProductCategory;
use App\Models\Reseller;
use App\Models\Store;
use App\Services\SalesDashboardExporter;
use App\Services\SalesDashboardService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * DASHBOARD PRODUK TERLARIS, PRIORITAS PRODUKSI & RETUR.
 * Pengaturan analisa (bisa dilipat) dibagikan ke semua widget lewat page filters.
 */
class SalesDashboard extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'dashboard-produk';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Dashboard Produk';

    protected static ?string $title = 'Dashboard Produk Terlaris & Prioritas Produksi';

    public function getSubheading(): ?string
    {
        return 'Otomatis dari penjualan (barang keluar), stok barang jadi, dan retur.';
    }

    public function getWidgets(): array
    {
        return [
            SummaryStats::class,
            TopModelsTable::class,
            TopVariantsTable::class,
            ProductionPriorityTable::class,
            MaterialNeedsTable::class,
            MarginTable::class,
            DeadStockTable::class,
            ReturnStats::class,
            ReturnVariantsTable::class,
            ReturnReasonsTable::class,
            ReturnModelsTable::class,
            ReturnStoresTable::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'xl' => 2];
    }

    public function filtersForm(Schema $schema): Schema
    {
        $defaults = app(SalesDashboardService::class)->defaults();

        return $schema
            ->columns(1)
            ->components([
                Section::make('Pengaturan Analisa')
                    ->description(fn (): string => $this->summary())
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->collapsible()
                    ->collapsed()
                    ->persistCollapsed()
                    ->compact()
                    ->columnSpanFull()
                    ->columns(['default' => 2, 'md' => 3, 'xl' => 6])
                    ->schema([
                        TextInput::make('days')
                            ->label('Periode analisa (hari)')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(366)
                            ->default($defaults['days'])
                            ->live(debounce: 700),
                        TextInput::make('target_days')
                            ->label('Target stok aman (hari jualan)')
                            ->integer()
                            ->minValue(0)
                            ->default($defaults['target_days'])
                            ->live(debounce: 700),
                        TextInput::make('lead_days')
                            ->label('Lama produksi / lead time (hari)')
                            ->integer()
                            ->minValue(0)
                            ->default($defaults['lead_days'])
                            ->live(debounce: 700),
                        TextInput::make('batch')
                            ->label('Saran dibulatkan ke kelipatan')
                            ->integer()
                            ->minValue(1)
                            ->default($defaults['batch'])
                            ->live(debounce: 700),
                        DatePicker::make('ref_date')
                            ->label('Tanggal acuan')
                            ->helperText('Default: tanggal penjualan terakhir')
                            ->default($defaults['ref_date'])
                            ->live(),
                        TextInput::make('dead_days')
                            ->label('Stok lambat laku jika tidak terjual (hari)')
                            ->integer()
                            ->minValue(1)
                            ->default($defaults['dead_days'])
                            ->live(debounce: 700),
                        Select::make('store_ids')
                            ->label('Toko')
                            ->options(fn () => Store::query()->orderBy('name')->pluck('name', 'id'))
                            ->multiple()
                            ->searchable()
                            ->placeholder('Semua toko')
                            ->live()
                            ->columnSpan(['default' => 2, 'md' => 1, 'xl' => 2]),
                        Select::make('channel_ids')
                            ->label('Channel')
                            ->options(fn () => Channel::query()->orderBy('name')->pluck('name', 'id'))
                            ->multiple()
                            ->placeholder('Semua channel')
                            ->live(),
                        Select::make('reseller_ids')
                            ->label('Reseller')
                            ->options(fn () => Reseller::query()->orderBy('name')->pluck('name', 'id'))
                            ->multiple()
                            ->searchable()
                            ->placeholder('Semua reseller')
                            ->live(),
                        Select::make('category_ids')
                            ->label('Kategori Produk')
                            ->options(fn () => ProductCategory::query()->orderBy('name')->pluck('name', 'id'))
                            ->multiple()
                            ->placeholder('Semua kategori')
                            ->live()
                            ->columnSpan(['default' => 2, 'md' => 1, 'xl' => 2]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make(collect(SalesDashboardExporter::FORMATS)
                ->map(fn (string $label, string $format): Action => Action::make("exportDashboard_{$format}")
                    ->label("Semua bagian – {$label}")
                    ->action(fn (SalesDashboardExporter $exporter): BinaryFileResponse => response()
                        ->download(
                            $exporter->export($this->filters ?? [], $format),
                            'dashboard-produk-'.Carbon::parse($this->filters['ref_date'] ?? today())->format('Ymd').'.'.$format,
                        )
                        ->deleteFileAfterSend()))
                ->values()
                ->all())
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->tooltip('Export semua bagian dashboard sesuai pengaturan aktif')
                ->button()
                ->color('gray'),
            Action::make('resetFilters')
                ->label('Pengaturan Default')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (): void {
                    $this->filters = null;
                    $this->getFiltersForm()->fill();
                    $this->updatedFilters();
                }),
        ];
    }

    /**
     * Ringkasan pengaturan aktif (tampil walau bagian pengaturan dilipat).
     */
    private function summary(): string
    {
        $f = [...app(SalesDashboardService::class)->defaults(), ...array_filter($this->filters ?? [], fn ($value): bool => filled($value))];
        $days = max(1, (int) $f['days']);
        $end = Carbon::parse($f['ref_date']);

        $scope = collect([
            'store_ids' => fn (array $ids) => Store::query()->whereIn('id', $ids)->pluck('name'),
            'channel_ids' => fn (array $ids) => Channel::query()->whereIn('id', $ids)->pluck('name'),
            'reseller_ids' => fn (array $ids) => Reseller::query()->whereIn('id', $ids)->pluck('name'),
            'category_ids' => fn (array $ids) => ProductCategory::query()->whereIn('id', $ids)->pluck('name'),
        ])->filter(fn ($query, string $key): bool => filled($f[$key] ?? null))
            ->map(fn ($query, string $key): string => $query((array) $f[$key])->implode(', '))
            ->implode(' · ');

        return "Periode {$days} hari ({$end->copy()->subDays($days - 1)->translatedFormat('d M Y')} – {$end->translatedFormat('d M Y')})"
            ." · target stok {$f['target_days']} hari · lead time {$f['lead_days']} hari · kelipatan {$f['batch']}"
            .($scope !== '' ? " · filter: {$scope}" : ' · semua toko & produk');
    }
}
