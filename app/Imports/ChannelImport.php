<?php

namespace App\Imports;

use App\Enums\ChannelKind;
use App\Models\Channel;
use App\Models\ChannelFeeRate;
use App\Support\SpreadsheetRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Channel penjualan: dicocokkan lewat KODE CHANNEL. FEE ADMIN % / IKLAN % diisi = ditambahkan ke riwayat fee
 * mulai FEE BERLAKU MULAI (fee yang sama dengan yang berlaku dilewati).
 */
class ChannelImport extends RowImporter
{
    public function title(): string
    {
        return 'Channel';
    }

    public function permission(): string
    {
        return 'kelola mitra';
    }

    public function columns(): array
    {
        return [
            'KODE CHANNEL' => ['code', 'Ya', 'Kode unik, mis. SHOPEE. Channel yang sudah ada diperbarui.'],
            'NAMA CHANNEL' => ['name', 'Channel baru', 'Default = kode.'],
            'JENIS CHANNEL' => ['kind', '-', 'Marketplace / Website / Chat (WA) / Offline / Lainnya. Channel baru default Marketplace.'],
            'FEE ADMIN %' => ['admin_fee', '-', 'Mis. 8 atau 8%.'],
            'IKLAN %' => ['ads', '-', 'Mis. 3 atau 3%.'],
            'FEE BERLAKU MULAI' => ['fee_from', '-', 'Tanggal fee mulai berlaku. Kosong = hari ini.'],
            'AKTIF' => ['is_active', '-', 'Ya / Tidak. Default Ya.'],
        ];
    }

    protected function requiredHeaders(): array
    {
        return ['code'];
    }

    protected function save(Collection $rows): void
    {
        /** @var SpreadsheetRow $row */
        $row = $rows->first();
        $row->requireText(['code' => 'KODE CHANNEL']);
        $row->maxLength(['code' => ['KODE CHANNEL', 20], 'name' => ['NAMA CHANNEL', 60]]);
        $kind = $row->enum('kind', ChannelKind::class, 'JENIS CHANNEL', ['chat' => ChannelKind::Chat, 'wa' => ChannelKind::Chat]);
        $adminFee = $row->percent('admin_fee', 'FEE ADMIN %');
        $ads = $row->percent('ads', 'IKLAN %');
        $feeFrom = $row->date('fee_from', 'FEE BERLAKU MULAI');
        $active = $row->bool('is_active');
        $row->throwIfInvalid();

        $code = Str::upper($row->text('code'));
        $attributes = array_filter(['name' => $row->text('name') ?: null, 'kind' => $kind, 'is_active' => $active], fn ($value): bool => $value !== null);
        $channel = $this->findCi(Channel::query(), 'code', $code);

        if ($channel === null) {
            $channel = Channel::create(['code' => $code, 'name' => $code, 'kind' => ChannelKind::Marketplace, ...$attributes]);
            $this->bump('created');
        } else {
            $this->saveIfDirty($channel->fill($attributes));
        }

        if ($adminFee === null && $ads === null) {
            return;
        }

        $from = $feeFrom ?? today()->toDateString();
        $current = $channel->feeRates()->whereDate('effective_from', '<=', $from)->orderByDesc('effective_from')->first();
        $adminFee ??= (float) ($current->admin_fee_pct ?? 0);
        $ads ??= (float) ($current->ads_pct ?? 0);

        if ($current !== null && round((float) $current->admin_fee_pct, 4) === round($adminFee, 4) && round((float) $current->ads_pct, 4) === round($ads, 4)) {
            return;
        }

        ChannelFeeRate::query()->updateOrCreate(
            ['channel_id' => $channel->id, 'effective_from' => $from],
            ['admin_fee_pct' => $adminFee, 'ads_pct' => $ads],
        );
        $this->bump('created', 'Riwayat Fee Channel');
    }

    protected function exportRows(): iterable
    {
        $percent = fn ($value): ?string => $value === null ? null : rtrim(rtrim(number_format((float) $value * 100, 2, '.', ''), '0'), '.').'%';

        foreach (Channel::query()->with(['feeRates' => fn ($query) => $query->whereDate('effective_from', '<=', today())->orderByDesc('effective_from')])->orderBy('code')->lazy() as $channel) {
            $fee = $channel->feeRates->first();

            yield [
                $channel->code, $channel->name, $channel->kind->getLabel(),
                $percent($fee?->admin_fee_pct), $percent($fee?->ads_pct), $fee?->effective_from?->toDateString(),
                $channel->is_active ? 'Ya' : 'Tidak',
            ];
        }
    }
}
