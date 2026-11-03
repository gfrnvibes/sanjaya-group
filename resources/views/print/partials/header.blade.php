{{--
    Toolbar cetak + kop dokumen. $title wajib; $sub (HTML aman, opsional) di bawah judul;
    $meta (teks tambahan baris kedua kanan, opsional); $printedBy dari halaman pemanggil.
--}}
<div class="toolbar">
    <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
    <span>Pilih printer <b>"Save as PDF" / "Simpan sebagai PDF"</b> untuk menyimpan sebagai file PDF.</span>
</div>

<header class="doc">
    <div>
        <div class="brand">{{ config('app.name') }}</div>
        <h1>{{ $title }}</h1>
        @isset($sub)
            <div class="sub">{{ $sub }}</div>
        @endisset
    </div>
    <div class="meta">
        Dicetak {{ now()->format('d/m/Y H:i') }}@if ($printedBy ?? null) oleh {{ $printedBy }}@endif
        @isset($meta)
            <br>{{ $meta }}
        @endisset
    </div>
</header>
