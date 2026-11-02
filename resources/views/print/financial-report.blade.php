<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $documentTitle }} — {{ now()->format('d-m-Y') }}</title>
    @include('filament.reports.styles')
    <style>
        @page { size: A4 portrait; margin: 12mm 10mm; }
        body { font-family: "Segoe UI", Arial, sans-serif; color: #111; margin: 0; padding: 16px; background: #fff; }
        header.doc { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid #111; padding-bottom: 6px; margin-bottom: 12px; }
        header.doc h1 { font-size: 18px; margin: 0; }
        header.doc .meta { font-size: 9px; color: #555; text-align: right; }
        section.report { margin-bottom: 18px; page-break-inside: auto; }
        section.report + section.report { page-break-before: always; }
        .fin-statement { font-size: 10.5px; }
        .fin-statement .fin-num { font-family: "Segoe UI", Arial, sans-serif; width: 9rem; }
        .fin-statement tr { page-break-inside: avoid; }
        .toolbar { position: sticky; top: 0; background: #fffbe6; border: 1px solid #f0d36b; padding: 8px 12px; margin-bottom: 12px; display: flex; gap: 8px; align-items: center; font-size: 12px; }
        .toolbar button { font-size: 12px; padding: 4px 12px; cursor: pointer; }
        @media print { .toolbar { display: none; } body { padding: 0; } }
        {!! $extraCss ?? '' !!}
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
        <span>Pilih printer <b>"Save as PDF" / "Simpan sebagai PDF"</b> untuk menyimpan sebagai file PDF.</span>
    </div>

    <header class="doc">
        <div><h1>{{ $documentTitle }}</h1></div>
        <div class="meta">{{ config('app.name') }}<br>Dicetak {{ now()->format('d/m/Y H:i') }}@if ($printedBy) oleh {{ $printedBy }}@endif</div>
    </header>

    {!! $prepend ?? '' !!}

    @foreach ($reports as $report)
        <section class="report">
            @include('filament.reports.statement', ['report' => $report])
        </section>
    @endforeach

    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    </script>
</body>
</html>
