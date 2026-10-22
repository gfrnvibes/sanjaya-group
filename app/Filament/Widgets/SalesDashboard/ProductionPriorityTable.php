<?php

namespace App\Filament\Widgets\SalesDashboard;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\ProductVariant;
use App\Models\Tailor;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Services\CostingService;
use App\Services\WorkOrderService;
use App\Support\ApplicationAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class ProductionPriorityTable extends DashboardTableWidget
{
    protected static ?int $sort = 4;

    protected function rows(array $dashboard): Collection
    {
        $priority = $this->tableFilters['priority']['value'] ?? null;

        return filled($priority) ? $dashboard['priority']->where('priority', $priority) : $dashboard['priority'];
    }

    protected function searchable(): array
    {
        return ['sku', 'name', 'model'];
    }

    public function table(Table $table): Table
    {
        $p = $this->dashboard()['params'];

        return $this->baseTable($table)
            ->heading('C. Prioritas Produksi')
            ->description("Urut HABIS → SEGERA → PERLU, lalu yang paling laku. Stok tersedia = stok gudang + SPK berjalan. Saran = terjual/hari × (target {$p['targetDays']} + lead time {$p['leadDays']} hari) − stok tersedia, dibulatkan ke kelipatan {$p['batch']}. SEGERA = stok habis sebelum produksi selesai.")
            ->defaultPaginationPageOption(25)
            ->columns([
                Columns::rank(),
                Columns::priority(),
                Columns::text('sku', 'SKU')->weight('medium'),
                Columns::text('name', 'Nama Produk')->wrap(),
                Columns::text('model', 'Model')->color('gray')->toggleable(),
                Columns::text('size', 'Ukuran')->placeholder('ALL'),
                Columns::stock('stock', 'Stok Gudang'),
                Columns::number('in_production', 'Dalam Produksi')->color('gray'),
                Columns::number('per_day', 'Terjual/Hari', 1),
                Columns::daysOfStock(),
                Columns::number('suggested', 'Saran Produksi (pcs)')->color('primary')->weight('bold'),
            ])
            ->filters([
                SelectFilter::make('priority')
                    ->label('Prioritas')
                    ->options(['1 HABIS' => 'Habis', '2 SEGERA' => 'Segera', '3 PERLU' => 'Perlu']),
            ])
            ->headerActions([
                $this->createWorkOrderAction(),
            ]);
    }

    /**
     * SKU HABIS & SEGERA dengan saran > 0, dipisah yang sudah punya resep (bisa langsung dipotong) dan yang belum.
     *
     * @return array{ready: Collection<int, array<string, mixed>>, without_bom: Collection<int, array<string, mixed>>}
     */
    private function candidates(): array
    {
        $rows = $this->dashboard()['priority']
            ->whereIn('priority', ['1 HABIS', '2 SEGERA'])
            ->where('suggested', '>', 0)
            ->values();
        $variants = ProductVariant::query()->whereIn('id', $rows->pluck('id'))->get()->keyBy('id');
        $costing = app(CostingService::class);

        // Sama dengan syarat WorkOrderService: resep ada dan semua bahannya (sesuai warna) terdaftar.
        [$ready, $withoutBom] = $rows->partition(function (array $row) use ($variants, $costing): bool {
            try {
                return $variants->has($row['id']) && $costing->calculate($variants[$row['id']], today()) !== [];
            } catch (BusinessRuleException) {
                return false;
            }
        });

        return ['ready' => $ready->values(), 'without_bom' => $withoutBom->values()];
    }

    /**
     * Buat SPK dari saran produksi: baris HABIS & SEGERA terisi otomatis, bisa diubah sebelum disimpan.
     */
    private function createWorkOrderAction(): Action
    {
        return Action::make('createWorkOrder')
            ->authorize(fn (): bool => ApplicationAccess::allows('kelola produksi'))
            ->label('Buat SPK dari Saran')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->color('primary')
            ->modalHeading('Buat SPK dari Prioritas Produksi')
            ->modalDescription(function (): string {
                $skipped = $this->candidates()['without_bom'];

                return 'Baris terisi dari SKU HABIS & SEGERA yang resep & bahannya lengkap, qty = saran produksi; ubah, hapus, atau tambah baris sesuai kapasitas. '
                    .'Menyimpan SPK langsung memotong stok bahan sesuai resep (status Dipotong).'
                    .($skipped->isNotEmpty() ? " {$skipped->count()} SKU dilewati karena resep/bahan belum lengkap: ".$skipped->take(5)->pluck('sku')->implode(', ').($skipped->count() > 5 ? ', …' : '').'.' : '');
            })
            ->modalWidth('4xl')
            ->fillForm(fn (): array => [
                'code' => null,
                'cut_date' => today()->toDateString(),
                'warehouse_id' => Warehouse::defaultForMaterials()?->id,
                'goods_warehouse_id' => Warehouse::defaultForGoods()?->id,
                'items' => $this->candidates()['ready']
                    ->take(50)
                    ->map(fn (array $row): array => ['variant_id' => $row['id'], 'qty_cut' => $row['suggested']])
                    ->values()
                    ->all(),
            ])
            ->schema([
                TextInput::make('code')->label('No. SPK')->helperText('Kosong = nomor otomatis')->maxLength(30)->unique(WorkOrder::class, 'code'),
                DatePicker::make('cut_date')->label('Tanggal Potong')->required(),
                Select::make('warehouse_id')->label('Gudang Bahan (dipotong)')->options(fn (): array => Warehouse::materialOptions())->required(),
                Select::make('goods_warehouse_id')->label('Gudang Barang Jadi (hasil)')->options(fn (): array => Warehouse::goodsOptions())->required(),
                Select::make('tailor_id')->label('Penjahit')->options(fn () => Tailor::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable(),
                Repeater::make('items')
                    ->label('Varian yang diproduksi')
                    ->table([
                        TableColumn::make('SKU'),
                        TableColumn::make('Qty Potong')->width('10rem'),
                    ])
                    ->schema([
                        Select::make('variant_id')
                            ->options(fn () => $this->dashboard()['priority']->mapWithKeys(fn (array $row): array => [
                                $row['id'] => "{$row['sku']} — {$row['priority']} (saran {$row['suggested']})",
                            ])->all())
                            ->searchable()
                            ->required()
                            ->distinct(),
                        TextInput::make('qty_cut')->integer()->minValue(1)->required(),
                    ])
                    ->minItems(1)
                    ->addActionLabel('Tambah varian')
                    ->columnSpanFull(),
                Textarea::make('note')->label('Catatan')->default('Dibuat dari Dashboard Produk (prioritas produksi).')->columnSpanFull(),
            ])
            ->action(function (array $data, WorkOrderService $workOrders): void {
                $items = array_values($data['items'] ?? []);
                unset($data['items']);

                try {
                    $workOrder = $workOrders->create($data, $items);
                } catch (BusinessRuleException $exception) {
                    Notification::make()->title('SPK tidak bisa dibuat')->body($exception->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()
                    ->title("SPK {$workOrder->code} dibuat")
                    ->body(count($items).' varian, '.number_format(array_sum(array_column($items, 'qty_cut')), 0, ',', '.').' pcs. Stok bahan sudah dipotong sesuai resep.')
                    ->success()
                    ->actions([
                        Action::make('openWorkOrder')->label('Buka SPK')->url(WorkOrderResource::getUrl('view', ['record' => $workOrder])),
                    ])
                    ->send();
            });
    }
}
