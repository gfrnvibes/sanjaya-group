{{-- Gaya bersama semua halaman cetak: kop, toolbar, judul bagian, catatan. Ukuran halaman diatur tiap halaman (@page). --}}
<style>
    * { box-sizing: border-box; }
    body { font-family: "Segoe UI", Arial, sans-serif; font-size: 11px; line-height: 1.4; color: #1a1a1a; margin: 0; padding: 20px; background: #fff; }
    header.doc { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; border-bottom: 3px solid #1a1a1a; padding-bottom: 8px; margin-bottom: 12px; }
    header.doc .brand { font-size: 11px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: #666; }
    header.doc h1 { font-size: 20px; margin: 2px 0 0; letter-spacing: -0.01em; }
    header.doc .sub { margin-top: 4px; color: #444; }
    header.doc .meta { color: #777; font-size: 9.5px; text-align: right; white-space: nowrap; }
    h2 { font-size: 13px; margin: 20px 0 6px; padding-bottom: 3px; border-bottom: 1.5px solid #1a1a1a; text-transform: uppercase; letter-spacing: 0.06em; break-after: avoid; }
    thead { display: table-header-group; }
    .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .muted { color: #555; }
    .pill { display: inline-block; padding: 1px 8px; border: 1px solid #ccc; border-radius: 999px; margin: 0 4px 4px 0; font-size: 10px; color: #333; }
    .note { margin-top: 16px; padding: 8px 10px; border-left: 3px solid #1a1a1a; background: #f7f7f7; font-size: 10.5px; white-space: pre-line; }
    .note.warn { border-left-color: #b45309; background: #fffbeb; color: #78350f; }
    .toolbar { position: sticky; top: 0; z-index: 1; background: #fffbe6; border: 1px solid #f0d36b; padding: 8px 12px; margin-bottom: 14px; display: flex; gap: 8px; align-items: center; font-size: 12px; }
    .toolbar button { font-size: 12px; padding: 4px 12px; cursor: pointer; }
    @media print { .toolbar { display: none; } body { padding: 0; } }
</style>
