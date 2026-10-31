<?php

namespace App\Filament\Resources\ResellerInvoices\Pages;

use App\Enums\InvoiceCycle;
use App\Filament\Actions\ResourceIo;
use App\Filament\Resources\ResellerInvoices\ResellerInvoiceResource;
use App\Models\Setting;
use App\Services\InvoiceSync;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

class ListResellerInvoices extends ListRecords
{
    protected static string $resource = ResellerInvoiceResource::class;

    public function getSubheading(): ?string
    {
        return InvoiceSync::enabled()
            ? 'Tagihan otomatis AKTIF ('.InvoiceSync::defaultCycle()->describe(InvoiceSync::defaultDay()).'): setiap penjualan & retur langsung masuk draft tagihan periodenya. Cek draft lalu klik Terbitkan.'
            : 'Tagihan otomatis nonaktif: buat tagihan manual lalu klik Tarik Penjualan Baru.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncInvoices')
                ->label('Sinkronkan sekarang')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (InvoiceSync $sync): void {
                    $result = $sync->sync(force: true);

                    Notification::make()
                        ->title('Tagihan reseller disinkronkan')
                        ->body("{$result['created']} draft baru, {$result['attached']} baris masuk tagihan, {$result['removed']} draft kosong dihapus.")
                        ->success()
                        ->send();
                }),
            Action::make('autoInvoiceSettings')
                ->label('Tagihan otomatis')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->modalHeading('Pengaturan tagihan reseller otomatis')
                ->modalDescription('Berlaku untuk semua reseller, kecuali reseller yang punya siklus sendiri (diatur di form Reseller). Draft yang sudah ada tidak diubah periodenya; siklus baru dipakai untuk draft berikutnya.')
                ->fillForm(fn (): array => [
                    'enabled' => InvoiceSync::enabled(),
                    'cycle' => InvoiceSync::defaultCycle()->value,
                    'day' => InvoiceSync::defaultDay(),
                ])
                ->schema([
                    Toggle::make('enabled')
                        ->label('Aktifkan tagihan otomatis')
                        ->helperText('Setiap penjualan / retur reseller langsung masuk draft tagihan periodenya; draft dibuat otomatis bila belum ada.'),
                    Select::make('cycle')
                        ->label('Siklus')
                        ->options(InvoiceCycle::class)
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('day', 1)),
                    Select::make('day')
                        ->label(fn (Get $get): string => $get('cycle') === InvoiceCycle::Weekly->value ? 'Mulai hari' : 'Mulai tanggal')
                        ->options(fn (Get $get): array => (InvoiceCycle::tryFrom((string) $get('cycle')) ?? InvoiceCycle::Monthly)->dayOptions())
                        ->helperText('Mis. bulanan mulai tanggal 26 = periode 26 s.d. 25 bulan berikutnya.')
                        ->required(),
                ])
                ->action(function (array $data, InvoiceSync $sync): void {
                    Setting::updateOrCreate(['key' => 'invoice_auto'], ['value' => $data['enabled'] ? 1 : 0]);
                    Setting::updateOrCreate(['key' => 'invoice_cycle'], ['value' => $data['cycle'] instanceof InvoiceCycle ? $data['cycle']->value : $data['cycle']]);
                    Setting::updateOrCreate(['key' => 'invoice_cycle_day'], ['value' => (int) $data['day']]);

                    $result = $data['enabled'] ? $sync->sync() : null;

                    Notification::make()
                        ->title('Pengaturan tagihan otomatis disimpan')
                        ->body($result === null ? 'Tagihan otomatis nonaktif.' : "{$result['created']} draft baru, {$result['attached']} baris masuk tagihan.")
                        ->success()
                        ->send();
                }),
            ...ResourceIo::make(),
            CreateAction::make(),
        ];
    }
}
