@php
    use App\Services\ResellerPriceList;
    $tierTitle = $tiers->count() === 1 ? ResellerPriceList::printLabel($tiers->first()) : $tiers->count().' tier';
    $rupiah = fn (float $value): string => 'Rp'.number_format($value, 0, ',', '.');
    $byModel = $layout === ResellerPriceList::BY_MODEL;
    $bySize = $layout === ResellerPriceList::BY_SIZE;
    $sizeCell = fn (?array $range): string => $range === null ? '–' : ($range[0] === $range[1] ? $rupiah($range[0]) : $rupiah($range[0]).'–'.$rupiah($range[1]));
    $landscape = $tiers->count() > 3 || ($bySize && collect($groups)->max(fn (array $group): int => count($group['columns'])) > 7);
    $priceHeading = fn ($tier): string => $tiers->count() === 1 ? 'Harga' : ResellerPriceList::printLabel($tier);
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daftar Harga Reseller — {{ $tierTitle }} — {{ $date->format('d-m-Y') }}</title>
    @include('print.partials.styles')
    <style>
        @page { size: A4 {{ $landscape ? 'landscape' : 'portrait' }}; margin: 12mm 11mm; }
        body { font-size: 11.5px; }
        .tier { display: inline-block; margin-top: 2px; padding: 2px 10px; border: 1.5px solid #1a1a1a; border-radius: 999px; font-weight: 700; font-size: 12px; }
        .valid { margin-left: 6px; color: #444; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-weight: 600; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.05em; color: #666; padding: 4px 8px; border-bottom: 1px solid #bbb; }
        td { padding: 6px 8px; vertical-align: top; border-bottom: 1px solid #e6e6e6; }
        td.price { font-weight: 700; font-size: 13px; }
        tbody { break-inside: avoid; }
        tbody.model:nth-of-type(even) td { background: #f7f7f7; }
        .name { font-weight: 700; font-size: 12.5px; }
        .code { color: #888; font-size: 9.5px; letter-spacing: 0.03em; }
        .size { white-space: nowrap; }
        tr.split td.size, tr.split td.price { border-top: 1px dashed #d4d4d4; }
        .chips { display: flex; flex-wrap: wrap; gap: 4px; justify-content: flex-end; }
        .chip { display: inline-flex; flex-direction: column; align-items: center; min-width: 74px; padding: 3px 6px; border: 1px solid #d4d4d4; border-radius: 4px; background: #fff; }
        .chip span { font-size: 9.5px; color: #666; }
        .chip b { font-size: 12px; font-variant-numeric: tabular-nums; }
        .model-cell b { font-size: 12.5px; margin-right: 4px; }
        table.sizes td { padding-top: 4px; padding-bottom: 4px; }
        table.sizes th.num, table.sizes td.num { padding-left: 4px; padding-right: 6px; }
        table.sizes td.price { font-size: 12px; }
        table.sizes th.span { text-align: center; border-bottom: 1px solid #ddd; }
        td.empty { color: #bbb; font-weight: 400; }
        td.tier-name { font-size: 10px; color: #666; white-space: nowrap; }
        tr.group td { padding-top: 10px; font-weight: 700; font-size: 12px; border-bottom: 1.5px solid #999; }
    </style>
</head>
<body>
    @include('print.partials.header', [
        'title' => 'Daftar Harga Reseller',
        'sub' => new Illuminate\Support\HtmlString(
            '<span class="tier">'.e($tiers->map(fn ($tier) => ResellerPriceList::printLabel($tier))->implode(' · ')).'</span>'
            .'<span class="valid">Harga per '.e($date->locale('id')->translatedFormat('d F Y')).'</span>'
        ),
        'meta' => number_format($count, 0, ',', '.').' '.($layout === ResellerPriceList::BY_SKU ? 'SKU' : 'model'),
    ])

    @forelse ($groups as $category => $items)
        <h2>{{ $category }}</h2>
        <table @class(['sizes' => $bySize])>
            @if ($bySize)
                @php($columns = $items['columns'])
                <thead>
                    <tr>
                        <th rowspan="2">Model</th>
                        @if ($tiers->count() > 1)
                            <th rowspan="2">Tier</th>
                        @endif
                        <th class="span" colspan="{{ count($columns) }}">Harga</th>
                    </tr>
                    <tr>
                        @foreach ($columns as $size)
                            <th class="num">{{ $size === '' ? 'Semua' : $size }}</th>
                        @endforeach
                    </tr>
                </thead>
                @foreach ($items['models'] as $model)
                    <tbody class="model">
                        @foreach ($tiers as $tier)
                            <tr @class(['split' => ! $loop->first])>
                                @if ($loop->first)
                                    <td rowspan="{{ $tiers->count() }}" class="model-cell"><b>{{ $model->code }}</b> <span class="muted">{{ $model->name }}</span></td>
                                @endif
                                @if ($tiers->count() > 1)
                                    <td class="tier-name">{{ ResellerPriceList::printLabel($tier) }}</td>
                                @endif
                                @foreach ($columns as $size)
                                    @php($range = $model->bySize[$size][$tier->id] ?? null)
                                    <td @class(['num', 'price', 'empty' => $range === null])>{{ $sizeCell($range) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            @elseif ($byModel)
                <thead>
                    <tr>
                        <th style="width: 34%">Model</th>
                        <th style="width: 18%">Ukuran</th>
                        @foreach ($tiers as $tier)
                            <th class="num" style="width: {{ $tiers->count() === 1 ? 15 : 11 }}%">{{ $priceHeading($tier) }}</th>
                        @endforeach
                    </tr>
                </thead>
                @foreach ($items as $model)
                    <tbody class="model">
                        @if ($tiers->count() === 1 && $model->lines->count() > 1)
                            {{-- Satu tier, harga beda per ukuran: ukuran & harga berderet menyamping agar hemat tempat. --}}
                            <tr>
                                <td class="model-cell"><b>{{ $model->code }}</b> <span class="muted">{{ $model->name }}</span></td>
                                <td colspan="2">
                                    <div class="chips">
                                        @foreach ($model->lines as $line)
                                            <div class="chip"><span>{{ $line->label }}</span><b>{{ $rupiah($line->prices[$tiers->first()->id]) }}</b></div>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @else
                            @foreach ($model->lines as $line)
                                <tr @class(['split' => ! $loop->first])>
                                    @if ($loop->first)
                                        <td rowspan="{{ $model->lines->count() }}" class="model-cell"><b>{{ $model->code }}</b> <span class="muted">{{ $model->name }}</span></td>
                                    @endif
                                    <td class="size">{{ $model->lines->count() === 1 ? ($model->sizes ?? '-') : $line->label }}</td>
                                    @foreach ($tiers as $tier)
                                        <td class="num price">{{ $rupiah($line->prices[$tier->id]) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endif
                    </tbody>
                @endforeach
            @else
                <thead>
                    <tr>
                        <th style="width: 17%">SKU</th>
                        <th>Nama Produk</th>
                        <th style="width: 14%">Warna</th>
                        <th style="width: 8%">Ukuran</th>
                        @foreach ($tiers as $tier)
                            <th class="num" style="width: {{ $tiers->count() === 1 ? 14 : 10 }}%">{{ $priceHeading($tier) }}</th>
                        @endforeach
                    </tr>
                </thead>
                @foreach ($items as $model => $rows)
                    <tbody>
                        <tr class="group"><td colspan="{{ 4 + $tiers->count() }}">{{ $model }}</td></tr>
                        @foreach ($rows as $row)
                            <tr>
                                <td class="code">{{ $row->sku }}</td>
                                <td>{{ $row->name }}</td>
                                <td class="muted">{{ $row->color ?? '-' }}</td>
                                <td class="size">{{ $row->size ?? '-' }}</td>
                                @foreach ($tiers as $tier)
                                    <td class="num price">{{ $rupiah($row->prices[$tier->id]) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            @endif
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
