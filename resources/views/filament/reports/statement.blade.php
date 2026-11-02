{{--
    Tabel laporan keuangan bertingkat (dipakai halaman & cetak).
    $report: title, entity, columns (label periode), rows (label, type, indent, amounts[], ratios[]), notes.
--}}
@php
    $money = fn ($value): string => $value === null ? '' : (($value < 0 ? '(' : '').'Rp'.number_format(abs((float) $value), 0, ',', '.').($value < 0 ? ')' : ''));
    $compare = count($report['columns']) > 1 && ! ($report['plain'] ?? false);
@endphp
<table class="fin-statement">
    <thead>
        <tr>
            <th class="fin-label">{{ $report['title'] }} — {{ $report['entity'] }}</th>
            @foreach ($report['columns'] as $column)
                <th class="fin-num">{{ $column }}</th>
            @endforeach
            @if ($compare)
                <th class="fin-num">Perubahan</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @foreach ($report['rows'] as $row)
            @if ($row['type'] === 'note')
                <tr class="fin-note"><td colspan="{{ count($report['columns']) + 1 + ($compare ? 1 : 0) }}">{{ $row['label'] }}</td></tr>
                @continue
            @endif
            <tr class="fin-{{ $row['type'] }}">
                <td class="fin-label" style="padding-left: {{ 0.5 + 1.1 * (int) $row['indent'] }}rem">{{ $row['label'] }}</td>
                @foreach ($row['amounts'] as $index => $amount)
                    <td @class(['fin-num', 'fin-negative' => (float) $amount < 0])>
                        {{ $row['type'] === 'header' ? '' : $money($amount) }}
                        @if (($row['ratios'][$index] ?? null) !== null)
                            <span class="fin-ratio">{{ number_format($row['ratios'][$index] * 100, 1, ',', '.') }}%</span>
                        @endif
                    </td>
                @endforeach
                @if ($compare)
                    @php
                        [$current, $previous] = [(float) ($row['amounts'][0] ?? 0), (float) ($row['amounts'][1] ?? 0)];
                    @endphp
                    <td class="fin-num fin-change">
                        @if ($row['type'] !== 'header' && ($current != 0 || $previous != 0))
                            {{ $money($current - $previous) }}
                            @if ($previous != 0)
                                <span class="fin-ratio">{{ ($current - $previous) / abs($previous) >= 0 ? '+' : '' }}{{ number_format(($current - $previous) / abs($previous) * 100, 1, ',', '.') }}%</span>
                            @endif
                        @endif
                    </td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>
@if (($report['notes'] ?? []) !== [])
    <ul class="fin-notes">
        @foreach ($report['notes'] as $note)
            <li>{{ $note }}</li>
        @endforeach
    </ul>
@endif
