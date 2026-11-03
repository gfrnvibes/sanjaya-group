<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $documentTitle }} — {{ now()->format('d-m-Y') }}</title>
    @include('filament.reports.styles')
    @include('print.partials.styles')
    <style>
        @page { size: A4 portrait; margin: 12mm 11mm; }
        section.report { margin-bottom: 18px; page-break-inside: auto; }
        section.report + section.report { page-break-before: always; }
        .fin-statement { font-size: 11px; }
        .fin-statement th { color: #666; border-bottom: 1.5px solid #1a1a1a; }
        .fin-statement th.fin-label { color: #1a1a1a; font-size: 11px; }
        .fin-statement td { border-bottom-color: #ececec; }
        .fin-statement .fin-num { font-family: "Segoe UI", Arial, sans-serif; width: 9rem; }
        .fin-statement tr { page-break-inside: avoid; }
        .fin-statement tr.fin-header td { border-bottom: 1px solid #999; }
        .fin-statement tr.fin-total td { background: #f2f2f2; border-top: 1.5px solid #1a1a1a; border-bottom: 1.5px solid #1a1a1a; }
        .fin-notes { color: #555; }
        {!! $extraCss ?? '' !!}
    </style>
</head>
<body>
    @include('print.partials.header', ['title' => $documentTitle])

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
