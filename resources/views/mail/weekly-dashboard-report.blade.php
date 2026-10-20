@php
    $p = $dashboard['params'];
    $k = $dashboard['kpi'];
    $prev = $dashboard['previous'];
    $r = $dashboard['returns'];
    $n = fn ($v) => number_format((float) $v, 0, ',', '.');
    $pct = fn ($v) => $v === null ? '-' : number_format($v * 100, 1, ',', '.').'%';
    $change = fn ($now, $before) => $before == 0 ? '' : ' ('.(($now - $before) >= 0 ? '+' : '').number_format(($now - $before) / abs($before) * 100, 1, ',', '.').'%)';
@endphp
<x-mail::message>
# Laporan Mingguan Produk

Periode **{{ $p['start']->translatedFormat('d M Y') }} – {{ $p['end']->translatedFormat('d M Y') }}** ({{ $p['days'] }} hari), dibanding periode sebelumnya.

<x-mail::table>
| Ringkasan | Nilai |
|:--|--:|
| Terjual | {{ $n($k['sold']) }} pcs{{ $change($k['sold'], $prev['sold']) }} |
| Omzet konveksi (tagihan) | Rp {{ $n($k['bill']) }}{{ $change($k['bill'], $prev['bill']) }} |
| Laba kotor konveksi | Rp {{ $n($k['bill'] - $k['hpp']) }} |
| SKU laku tapi stok HABIS | {{ $n($k['out_of_stock']) }} |
| SKU harus SEGERA diproduksi | {{ $n($k['urgent']) }} |
| Total saran produksi | {{ $n($k['suggested']) }} pcs |
| Retur | {{ $n($r['qty']) }} pcs ({{ $pct($r['rate']) }}) |
</x-mail::table>

@if ($dashboard['priority']->isNotEmpty())
## Prioritas produksi teratas

<x-mail::table>
| Prioritas | SKU | Stok | Saran |
|:--|:--|--:|--:|
@foreach ($dashboard['priority']->take(10) as $row)
| {{ $row['priority'] }} | {{ $row['sku'] }} | {{ $n($row['stock']) }} | {{ $n($row['suggested']) }} |
@endforeach
</x-mail::table>
@endif

@if ($r['by_variant']->isNotEmpty())
## Retur terbanyak

<x-mail::table>
| SKU | Retur | % Retur | Alasan |
|:--|--:|--:|:--|
@foreach ($r['by_variant']->take(5) as $row)
| {{ $row['sku'] }} | {{ $n($row['returned']) }} | {{ $pct($row['return_rate']) }} | {{ $row['top_reason'] ?? '-' }} |
@endforeach
</x-mail::table>
@endif

Detail lengkap (terlaris, prioritas, kebutuhan bahan, margin, stok lambat laku, retur) ada di lampiran Excel.

<x-mail::button :url="\App\Filament\Pages\SalesDashboard::getUrl()">
Buka Dashboard Produk
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
