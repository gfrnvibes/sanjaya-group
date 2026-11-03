<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ now()->format('d/m/Y H:i') }}</title>
    @include('print.partials.styles')
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        body { font-size: 10.5px; }
        .filters { margin: -4px 0 10px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { text-align: left; font-weight: 600; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; color: #666; padding: 5px 6px; border-bottom: 1.5px solid #1a1a1a; vertical-align: bottom; }
        table.data td { padding: 4px 6px; border-bottom: 1px solid #e6e6e6; vertical-align: top; }
        table.data tbody tr:nth-child(even) td { background: #f7f7f7; }
        table.data tr { break-inside: avoid; }
        table.data td.no, table.data th.no { width: 1%; color: #999; }
        table.data td.num { font-variant-numeric: tabular-nums; }
    </style>
</head>
<body>
    @include('print.partials.header', ['meta' => number_format(count($rows), 0, ',', '.').' baris'])

    @if ($filters !== [] || filled($search))
        <div class="filters">
            <span class="muted">Filter:</span>
            @foreach ($filters as $filter)
                <span class="pill">{{ $filter }}</span>
            @endforeach
            @if (filled($search))
                <span class="pill">Pencarian: "{{ $search }}"</span>
            @endif
        </div>
    @endif

    <table class="data">
        <thead>
            <tr>
                <th class="num no">#</th>
                @foreach ($headers as $index => $header)
                    <th @class(['num' => $numeric[$index]])>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $number => $row)
                <tr>
                    <td class="num no">{{ $number + 1 }}</td>
                    @foreach ($row as [$text, $alignRight])
                        <td @class(['num' => $alignRight])>{{ $text }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($headers) + 1 }}" class="muted">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($truncated)
        <p class="note warn">Hanya {{ number_format($limit, 0, ',', '.') }} baris pertama yang dicetak. Gunakan export Excel untuk data lengkap.</p>
    @endif

    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    </script>
</body>
</html>
