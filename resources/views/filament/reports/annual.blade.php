{{--
    Isi Laporan Tahunan (halaman & cetak): $annual dari App\Services\AnnualReport::build().
    $print = true untuk versi cetak (sampul & pemisah halaman).
--}}
@php
    $money = fn ($value): string => $value === null ? '-' : (($value < 0 ? '(' : '').'Rp'.number_format(abs((float) $value), 0, ',', '.').($value < 0 ? ')' : ''));
    $change = fn ($current, $previous): ?string => (float) $previous == 0.0 ? null : (($current - $previous) / abs($previous) >= 0 ? '+' : '').number_format(($current - $previous) / abs($previous) * 100, 1, ',', '.').'%';
    $ratio = fn (array $ratio): string => $ratio['value'] === null ? '-' : match ($ratio['format']) {
        'percent' => number_format($ratio['value'] * 100, 1, ',', '.').'%',
        'times' => number_format($ratio['value'], 1, ',', '.').'x',
        default => number_format($ratio['value'], 0, ',', '.').' hari',
    };
    $print ??= false;
@endphp

<style>
    .ar-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 0.5rem; margin-bottom: 1rem; }
    .ar-kpi { border: 1px solid var(--gray-200, #e4e4e7); padding: 0.6rem 0.75rem; }
    .ar-kpi .ar-label { font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-500, #71717a); }
    .ar-kpi .ar-value { font-size: 1.05rem; font-weight: 700; font-variant-numeric: tabular-nums; margin: 0.15rem 0; }
    .ar-kpi .ar-sub { font-size: 0.6875rem; color: var(--gray-500, #71717a); }
    .ar-h2 { font-size: 0.875rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin: 1.25rem 0 0.5rem; }
    .ar-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr)); gap: 1rem; }
    .ar-up { color: var(--success-600, #16a34a); } .ar-down { color: var(--danger-600, #dc2626); }
    .dark .ar-kpi { border-color: rgb(255 255 255 / 0.1); }
</style>

@if ($print)
    <div style="text-align:center; padding: 30vh 0 0; page-break-after: always;">
        <div style="font-size: 14px; letter-spacing: .2em; text-transform: uppercase; color: #555;">Laporan Tahunan</div>
        <div style="font-size: 34px; font-weight: 700; margin: 8px 0;">{{ config('app.name') }}</div>
        <div style="font-size: 18px;">Tahun {{ $annual['year'] }}</div>
        <div style="font-size: 11px; color: #555; margin-top: 6px;">Periode {{ $annual['from']->locale('id')->translatedFormat('d F Y') }} – {{ $annual['until']->locale('id')->translatedFormat('d F Y') }}</div>
    </div>
@endif

<div class="ar-h2">1. Ringkasan {{ $annual['year'] }} vs {{ $annual['year'] - 1 }}</div>
<div class="ar-kpis">
    @foreach ($annual['kpis'] as $kpi)
        @php($delta = $change($kpi['value'], $kpi['previous']))
        <div class="ar-kpi">
            <div class="ar-label">{{ $kpi['label'] }}</div>
            <div class="ar-value">{{ $money($kpi['value']) }}</div>
            <div class="ar-sub">Tahun lalu {{ $money($kpi['previous']) }}
                @if ($delta) · <span @class(['ar-up' => ! str_starts_with($delta, '-'), 'ar-down' => str_starts_with($delta, '-')])>{{ $delta }}</span>@endif
            </div>
        </div>
    @endforeach
</div>

<div class="ar-h2">2. Laba Rugi per Bulan</div>
<div class="fin-scroll">
    <table class="fin-statement">
        <thead>
            <tr>
                <th class="fin-label">Keterangan</th>
                @foreach ($annual['monthly']['months'] as $month)
                    <th class="fin-num" style="width:auto">{{ $month }}</th>
                @endforeach
                <th class="fin-num" style="width:auto">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($annual['monthly']['rows'] as $row)
                <tr @class(['fin-total' => $row['strong'], 'fin-line' => ! $row['strong']])>
                    <td class="fin-label">{{ $row['label'] }}</td>
                    @foreach ($row['values'] as $value)
                        <td @class(['fin-num', 'fin-negative' => $value < 0]) style="width:auto">{{ number_format($value / 1000, 0, ',', '.') }}</td>
                    @endforeach
                    <td @class(['fin-num', 'fin-negative' => $row['total'] < 0]) style="width:auto"><b>{{ number_format($row['total'] / 1000, 0, ',', '.') }}</b></td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <ul class="fin-notes"><li>Dalam ribuan rupiah (Rp000). HPP & beban bernilai negatif (pengurang).</li></ul>
</div>

@foreach ($annual['reports'] as $index => $report)
    <div class="ar-h2" @if ($print) style="page-break-before: always;" @endif>{{ $index + 3 }}. {{ $report['title'] }}</div>
    <div class="fin-scroll">@include('filament.reports.statement', ['report' => $report])</div>
@endforeach

<div class="ar-h2" @if ($print) style="page-break-before: always;" @endif>7. Analisis & Rasio</div>
<div class="ar-kpis">
    @foreach ($annual['ratios'] as $item)
        <div class="ar-kpi">
            <div class="ar-label">{{ $item['label'] }}</div>
            <div class="ar-value">{{ $ratio($item) }}</div>
            <div class="ar-sub">{{ $item['hint'] }}</div>
        </div>
    @endforeach
</div>
<div class="ar-grid">
    @foreach ($annual['analysis'] as $title => $rows)
        <div>
            <table class="fin-statement">
                <thead>
                    <tr>
                        <th class="fin-label">{{ $title }}</th>
                        <th class="fin-num" style="width:auto">{{ $annual['year'] }}</th>
                        <th class="fin-num" style="width:auto">{{ $annual['year'] - 1 }}</th>
                        <th class="fin-num" style="width:auto">Tumbuh</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php($isQty = str_contains($title, 'pcs'))
                        <tr class="fin-line">
                            <td class="fin-label">{{ $row['name'] }}</td>
                            <td class="fin-num" style="width:auto">{{ $isQty ? number_format($row['current'], 0, ',', '.') : $money($row['current']) }}</td>
                            <td class="fin-num" style="width:auto">{{ $isQty ? number_format($row['previous'], 0, ',', '.') : $money($row['previous']) }}</td>
                            <td class="fin-num" style="width:auto">{{ $change($row['current'], $row['previous']) ?? 'baru' }}</td>
                        </tr>
                    @empty
                        <tr class="fin-note"><td colspan="4">Belum ada penjualan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach
</div>
<ul class="fin-notes">
    <li>Disusun dari transaksi tercatat (tanpa jurnal berpasangan). Beban yang belum diinput belum mengurangi laba; aset tetap & pinjaman belum dicatat.</li>
    @if (\App\Services\FinancialStatements::taxEnabled())
        <li>Pajak = {{ \App\Services\FinancialStatements::percent(\App\Services\FinancialStatements::taxRate()) }} dari laba kumulatif tahun berjalan.</li>
    @endif
</ul>
