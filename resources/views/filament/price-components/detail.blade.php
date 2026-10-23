@php
    $rp = fn ($value) => $value === null ? '-' : 'Rp ' . number_format((float) $value, 0, ',', '.');
    $num = fn ($value) => $value === null ? '-' : rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',');
@endphp

<div class="space-y-5 text-sm">
    <div>
        <h3 class="mb-2 font-semibold text-gray-950 dark:text-white">
            {{ $detail['kind'] === 'beli_jadi' ? 'HPP beli jadi (harga beli + packing)' : 'Resep (BOM) & HPP' }}
        </h3>

        @if ($detail['error'])
            <p class="mb-2 rounded-lg bg-danger-50 px-3 py-2 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">{{ $detail['error'] }}</p>
        @endif

        @if ($detail['lines'] !== [])
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pe-3 font-medium">Komponen</th>
                            <th class="py-2 pe-3 font-medium">Keterangan</th>
                            <th class="py-2 pe-3 text-right font-medium">{{ $detail['kind'] === 'beli_jadi' ? 'Qty beli' : 'Pemakaian / pcs' }}</th>
                            <th class="py-2 pe-3 text-right font-medium">Harga satuan</th>
                            <th class="py-2 text-right font-medium">Per pcs</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($detail['lines'] as $line)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pe-3 font-medium">{{ $line['label'] }}</td>
                                <td class="py-1.5 pe-3 text-gray-500 dark:text-gray-400">{{ $line['detail'] }}</td>
                                <td class="py-1.5 pe-3 text-right">{{ $num($line['qty']) }}</td>
                                <td class="py-1.5 pe-3 text-right">{{ $line['unit'] === null ? '-' : 'Rp ' . number_format((float) $line['unit'], 2, ',', '.') }}</td>
                                <td class="py-1.5 text-right">{{ $rp($line['subtotal']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td colspan="4" class="py-2 pe-3 text-right">HPP dihitung hari ini</td>
                            <td class="py-2 text-right">{{ $rp($detail['total']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif

        @if ($detail['note'])
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $detail['note'] }}</p>
        @endif
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">HPP tersimpan (berlaku)</div>
            <div class="text-base font-semibold">{{ $rp($cost?->total_cost) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                @if ($cost)
                    Bahan/harga beli {{ $rp($cost->material_cost) }} · ongkos/packing {{ $rp($cost->labor_cost) }} · mulai {{ $cost->effective_from->format('d/m/Y') }}
                @else
                    Belum ada
                @endif
            </div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">Margin berlaku</div>
            <div class="text-base font-semibold">{{ $margin }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Usulan harga dasar: {{ $rp($proposed) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">Harga dasar berlaku</div>
            <div class="text-base font-semibold">{{ $rp($price?->base_price) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $price ? 'Mulai ' . $price->effective_from->format('d/m/Y') . ($price->note ? ' · ' . $price->note : '') : 'Belum ada harga' }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">Harga reseller per tier</div>
            @forelse ($tiers as $tier)
                <div class="flex justify-between"><span>{{ $tier['name'] }}</span><span class="font-medium">{{ $rp($tier['price']) }}</span></div>
            @empty
                <div>Belum ada tier.</div>
            @endforelse
        </div>
    </div>

    @if (($history ?? collect())->isNotEmpty())
        <div>
            <h3 class="mb-2 font-semibold text-gray-950 dark:text-white">Riwayat harga dasar</h3>
            <table class="w-full">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pe-3 font-medium">Berlaku mulai</th>
                        <th class="py-2 pe-3 text-right font-medium">Harga dasar</th>
                        <th class="py-2 pe-3 text-right font-medium">Margin</th>
                        <th class="py-2 pe-3 font-medium">Sumber</th>
                        <th class="py-2 text-right font-medium">Dipakai pesanan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($history as $row)
                        <tr @class(['border-b border-gray-100 dark:border-white/5', 'font-semibold' => $price && $row->id === $price->id])>
                            <td class="py-1.5 pe-3">
                                {{ $row->effective_from->format('d/m/Y') }}
                                @if ($row->effective_from->gt(today()))
                                    <span class="text-xs text-warning-600">(terjadwal)</span>
                                @elseif ($price && $row->id === $price->id)
                                    <span class="text-xs text-success-600">(berlaku)</span>
                                @endif
                            </td>
                            <td class="py-1.5 pe-3 text-right">{{ $rp($row->base_price) }}</td>
                            <td class="py-1.5 pe-3 text-right">{{ $row->margin_pct === null ? '-' : rtrim(rtrim(number_format((float) $row->margin_pct * 100, 2, ',', '.'), '0'), ',') . '%' }}</td>
                            <td class="py-1.5 pe-3 text-gray-500 dark:text-gray-400">{{ $row->source?->getLabel() }}{{ $row->note ? ' · ' . $row->note : '' }}</td>
                            <td class="py-1.5 text-right">{{ number_format($row->sales_order_items_count, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Pesanan menyimpan harga saat transaksi, jadi perubahan harga tidak mengubah pesanan lama.</p>
        </div>
    @endif
</div>
