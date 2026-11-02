@php
    use App\Services\ResellerPriceList;
    $tierTitle = $tiers->count() === 1 ? ResellerPriceList::printLabel($tiers->first()) : $tiers->count().' tier';
    $rupiah = fn (float $value): string => 'Rp'.number_format($value, 0, ',', '.');
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daftar Harga Reseller — {{ $tierTitle }} — {{ $date->format('d-m-Y') }}</title>
    <style>
        @page { size: A4 {{ $tiers->count() > 3 ? 'landscape' : 'portrait' }}; margin: 12mm 10mm; }
        * { box-sizing: border-box; }
        body { font-family: "Segoe UI", Arial, sans-serif; font-size: 10.5px; color: #111; margin: 0; padding: 16px; background: #fff; }
        header { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; border-bottom: 2px solid #111; padding-bottom: 6px; margin-bottom: 10px; }
        h1 { font-size: 18px; margin: 0; letter-spacing: -0.01em; }
        .sub { font-size: 11px; color: #333; margin-top: 2px; }
        .meta { color: #555; font-size: 9px; text-align: right; }
        h2 { font-size: 12px; margin: 14px 0 4px; padding: 3px 6px; background: #111; color: #fff; text-transform: uppercase; letter-spacing: 0.05em; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        th { background: #f2f2f2; text-align: left; font-weight: 600; font-size: 9.5px; }
        th, td { border: 1px solid #d0d0d0; padding: 3px 6px; vertical-align: top; }
        td.num, th.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        td.price { font-weight: 600; }
        tr.model td { background: #fafafa; font-weight: 600; border-top: 1.5px solid #999; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .note { margin-top: 10px; font-size: 9.5px; color: #333; white-space: pre-line; }
        .toolbar { position: sticky; top: 0; background: #fffbe6; border: 1px solid #f0d36b; padding: 8px 12px; margin-bottom: 12px; display: flex; gap: 8px; align-items: center; font-size: 12px; }
        .toolbar button { font-size: 12px; padding: 4px 12px; cursor: pointer; }
        @media print { .toolbar { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
        <span>Pilih printer <b>"Save as PDF" / "Simpan sebagai PDF"</b> untuk menyimpan sebagai file PDF.</span>
    </div>

    <header>
        <div>
            <h1>Daftar Harga Reseller</h1>
            <div class="sub">
                {{ $tiers->map(fn ($tier) => ResellerPriceList::printLabel($tier))->implode(', ') }}
                · Berlaku {{ $date->locale('id')->translatedFormat('d F Y') }}
            </div>
        </div>
        <div class="meta">
            {{ config('app.name') }}<br>
            Dicetak {{ now()->format('d/m/Y H:i') }}@if ($printedBy) oleh {{ $printedBy }}@endif · {{ number_format($count, 0, ',', '.') }} produk
        </div>
    </header>

    @forelse ($groups as $category => $models)
        <h2>{{ $category }}</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 16%">SKU</th>
                    <th>Nama Produk</th>
                    <th style="width: 13%">Warna</th>
                    <th style="width: 8%">Ukuran</th>
                    @foreach ($tiers as $tier)
                        <th class="num" style="width: {{ $tiers->count() === 1 ? 14 : 10 }}%">{{ $tiers->count() === 1 ? 'Harga' : ResellerPriceList::printLabel($tier) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($models as $model => $rows)
                    <tr class="model"><td colspan="{{ 4 + $tiers->count() }}">{{ $model }}</td></tr>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row->sku }}</td>
                            <td>{{ $row->name }}</td>
                            <td>{{ $row->color ?? '-' }}</td>
                            <td>{{ $row->size ?? '-' }}</td>
                            @foreach ($tiers as $tier)
                                <td class="num price">{{ $rupiah($row->prices[$tier->id]) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @empty
        <p>Tidak ada produk aktif yang punya harga pada tanggal ini.</p>
    @endforelse

    @if (filled($note))
        <div class="note">{{ $note }}</div>
    @endif

    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    </script>
</body>
</html>
