<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ now()->format('d/m/Y H:i') }}</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        * { box-sizing: border-box; }
        body { font-family: "Segoe UI", Arial, sans-serif; font-size: 10px; color: #111; margin: 0; padding: 16px; background: #fff; }
        header { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; margin-bottom: 8px; }
        h1 { font-size: 16px; margin: 0; }
        .meta { color: #555; font-size: 9px; text-align: right; }
        .filters { margin: 4px 0 10px; color: #333; font-size: 9px; }
        .filters span { display: inline-block; border: 1px solid #ccc; border-radius: 3px; padding: 1px 6px; margin: 0 4px 3px 0; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f2f2f2; text-align: left; font-weight: 600; }
        th, td { border: 1px solid #d0d0d0; padding: 3px 5px; vertical-align: top; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .toolbar { position: sticky; top: 0; background: #fffbe6; border: 1px solid #f0d36b; padding: 8px 12px; margin-bottom: 12px; display: flex; gap: 8px; align-items: center; font-size: 12px; }
        .toolbar button { font-size: 12px; padding: 4px 12px; cursor: pointer; }
        .note { color: #a15c00; font-size: 9px; margin-top: 6px; }
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
            <h1>{{ $title }}</h1>
        </div>
        <div class="meta">
            {{ config('app.name') }} · Dicetak {{ now()->format('d/m/Y H:i') }}@if ($printedBy) oleh {{ $printedBy }}@endif<br>
            {{ number_format(count($rows), 0, ',', '.') }} baris
        </div>
    </header>

    @if ($filters !== [] || filled($search))
        <div class="filters">
            Filter:
            @foreach ($filters as $filter)
                <span>{{ $filter }}</span>
            @endforeach
            @if (filled($search))
                <span>Pencarian: "{{ $search }}"</span>
            @endif
        </div>
    @endif

    <table>
        <thead>
            <tr>
                <th class="num">#</th>
                @foreach ($headers as $index => $header)
                    <th @class(['num' => $numeric[$index]])>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $number => $row)
                <tr>
                    <td class="num">{{ $number + 1 }}</td>
                    @foreach ($row as [$text, $alignRight])
                        <td @class(['num' => $alignRight])>{{ $text }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($headers) + 1 }}">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($truncated)
        <p class="note">Hanya {{ number_format($limit, 0, ',', '.') }} baris pertama yang dicetak. Gunakan export Excel untuk data lengkap.</p>
    @endif

    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    </script>
</body>
</html>
