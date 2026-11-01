{{--
    Tema panel "Sharp + Compact" (meniru gaya tema berbayar Filament Sharp, tanpa build npm):
    sudut tajam, tanpa bayangan (garis 1px), jarak rapat, label huruf kapital kecil, angka monospace.
    Dimuat setelah CSS Filament lewat render hook STYLES_AFTER di AdministratorPanelProvider.
--}}
<style>
    /* ---------- sudut tajam: semua radius Filament memakai variabel ini (rounded-full untuk avatar/toggle tetap) */
    :root {
        --radius-xs: 0; --radius-sm: 0; --radius-md: 0; --radius-lg: 0; --radius-xl: 0; --radius-2xl: 0; --radius-3xl: 0;
        --sharp-border: var(--gray-200);
        --sharp-muted-bg: var(--gray-50);
    }
    .dark { --sharp-border: rgb(255 255 255 / 0.1); --sharp-muted-bg: rgb(255 255 255 / 0.03); }

    /* ---------- tanpa bayangan: kartu cukup garis 1px */
    .fi-section, .fi-ta-ctn, .fi-wi-stats-overview-stat, .fi-dropdown-panel, .fi-modal-window,
    .fi-input-wrp, .fi-btn, .fi-tabs, .fi-fo-repeater-item, .fi-sidebar-header { --tw-shadow: 0 0 #0000; }

    /* ---------- halaman lebih padat */
    .fi-page-header-main-ctn { row-gap: calc(var(--spacing) * 4); padding-block: calc(var(--spacing) * 5) calc(var(--spacing) * 6); }
    .fi-header .fi-header-heading { font-size: 1.5rem; line-height: 2rem; font-weight: 600; letter-spacing: -0.015em; }
    .fi-header .fi-header-subheading { font-size: 0.8125rem; margin-top: 0.125rem; }
    .fi-breadcrumbs { font-size: 0.8125rem; }

    /* ---------- section */
    .fi-section:not(.fi-section-not-contained):not(.fi-aside) > .fi-section-header { padding-inline: calc(var(--spacing) * 4); padding-block: calc(var(--spacing) * 3); }
    .fi-section:not(.fi-section-not-contained):not(.fi-divided) > .fi-section-content-ctn > .fi-section-content,
    .fi-section:not(.fi-section-not-contained).fi-divided > .fi-section-content-ctn > .fi-section-content > * { padding: calc(var(--spacing) * 4); }
    .fi-section:not(.fi-section-not-contained) > .fi-section-content-ctn > .fi-section-footer { padding-inline: calc(var(--spacing) * 4); padding-block: calc(var(--spacing) * 3); }
    .fi-section-header-heading { font-size: 0.875rem; font-weight: 600; }

    /* ---------- statistik (kartu ringkasan) */
    .fi-wi-stats-overview-stat { padding: calc(var(--spacing) * 3) calc(var(--spacing) * 4); min-width: 0; }
    .fi-wi-stats-overview-stat-content { row-gap: calc(var(--spacing) * 1); }
    .fi-wi-stats-overview-stat-label { font-size: 0.6875rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.06em; color: var(--gray-500); }
    /* nominal mengecil mengikuti lebar layar & tidak keluar kartu */
    .fi-wi-stats-overview-stat-value {
        font-family: var(--mono-font-family), ui-monospace, monospace; font-size: clamp(1.125rem, 1.4vw, 1.375rem); line-height: 1.75rem;
        font-weight: 600; letter-spacing: -0.03em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .fi-wi-stats-overview-stat-description { font-size: 0.75rem; }

    /* ---------- tabel */
    .fi-ta-header-cell, .fi-ta-header-group-cell {
        padding-block: calc(var(--spacing) * 2); font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;
        color: var(--gray-500); background-color: var(--sharp-muted-bg);
    }
    .fi-ta-header-cell .fi-ta-header-cell-sort-btn { gap: calc(var(--spacing) * 1); }
    /* baris padat (~34px): tinggi baris ditentukan sel checkbox & sel aksi, bukan hanya sel teks */
    .fi-ta-text:not(.fi-inline), .fi-ta-icon:not(.fi-inline), .fi-ta-color:not(.fi-inline),
    .fi-ta-image:not(.fi-inline), .fi-ta-select:not(.fi-inline), .fi-ta-placeholder:not(.fi-inline) { padding-block: calc(var(--spacing) * 1.75); }
    .fi-ta-text-item { font-size: 0.8125rem; line-height: 1.25rem; }
    .fi-ta-text-item .fi-ta-text-description, .fi-ta-text .fi-ta-text-description { font-size: 0.75rem; line-height: 1rem; }
    .fi-ta-selection-cell { padding-block: calc(var(--spacing) * 1.5); }
    .fi-ta-record-checkbox, .fi-ta-page-checkbox { margin-block: calc(var(--spacing) * 1.5); }
    .fi-ta-cell:has(.fi-ta-actions) { padding-block: calc(var(--spacing) * 1); }
    .fi-ta-cell .fi-ta-actions .fi-icon-btn { width: calc(var(--spacing) * 7); height: calc(var(--spacing) * 7); margin: calc(var(--spacing) * -1); }
    .fi-ta-cell .fi-ta-actions .fi-link, .fi-ta-cell .fi-ta-actions .fi-btn { font-size: 0.8125rem; }
    .fi-ta-summary-row .fi-ta-cell, .fi-ta-summary-header-cell { padding-block: calc(var(--spacing) * 1.5); }
    .fi-ta-group-header { padding-block: calc(var(--spacing) * 1.5); }
    .fi-ta-cell { border-right: 1px solid var(--sharp-border); }
    .fi-ta-cell:last-child { border-right: 0; }
    /* angka rata kanan (uang, qty) & ringkasan: monospace agar digit sejajar */
    .fi-ta-text.fi-align-end .fi-ta-text-item, .fi-ta-text.fi-align-right .fi-ta-text-item, .fi-ta-summary-row .fi-ta-text-item {
        font-family: var(--mono-font-family), ui-monospace, monospace; font-size: 0.8125rem; font-variant-numeric: tabular-nums;
    }
    .fi-ta-header { padding-block: calc(var(--spacing) * 3); }
    .fi-ta-header-toolbar { padding-block: calc(var(--spacing) * 2); }
    .fi-ta-ctn .fi-pagination { padding-block: calc(var(--spacing) * 2); }

    /* ---------- badge: kotak kecil, huruf kapital */
    .fi-badge { font-size: 0.625rem; line-height: 1rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; padding-inline: calc(var(--spacing) * 1.5); padding-block: 0; min-height: 1.25rem; }
    .fi-badge .fi-icon { width: 0.75rem; height: 0.75rem; }
    /* angka di dalam badge (mis. jumlah di tab / navigasi) */
    .fi-tabs-item .fi-badge, .fi-sidebar-item .fi-badge { font-family: var(--mono-font-family), ui-monospace, monospace; letter-spacing: 0; }

    /* ---------- tombol & isian sedikit lebih pendek */
    .fi-btn { padding-block: calc(var(--spacing) * 1.75); }
    .fi-btn.fi-size-sm, .fi-btn.fi-size-xs { padding-block: calc(var(--spacing) * 1.25); }
    .fi-input { padding-block: calc(var(--spacing) * 1.5); }

    /* ---------- tab bergaris bawah (bukan pil) */
    .fi-tabs:not(.fi-contained) { column-gap: calc(var(--spacing) * 0.5); border-bottom: 1px solid var(--sharp-border); padding-inline: 0; }
    .fi-tabs:not(.fi-contained) .fi-tabs-item { padding-block: calc(var(--spacing) * 2); margin-bottom: -1px; }
    .fi-tabs:not(.fi-contained) .fi-tabs-item.fi-active { background-color: transparent; box-shadow: inset 0 -2px 0 var(--primary-600); }
    .dark .fi-tabs:not(.fi-contained) .fi-tabs-item.fi-active { box-shadow: inset 0 -2px 0 var(--primary-400); }

    /* ---------- sidebar (tanpa topbar: brand, pencarian, menu user, notifikasi ada di sini) */
    .fi-sidebar { border-inline-end: 1px solid var(--sharp-border); }
    .fi-sidebar-header { box-shadow: none; border-bottom: 1px solid var(--sharp-border); }
    .fi-sidebar-group-label { font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--gray-500); line-height: 1.5rem; }
    .fi-sidebar-item-btn { padding-block: calc(var(--spacing) * 1.5); padding-inline: calc(var(--spacing) * 2.5); }
    .fi-sidebar-item-label { font-size: 0.8125rem; }
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn { box-shadow: inset 2px 0 0 var(--primary-600); }
    .dark .fi-sidebar-item.fi-active > .fi-sidebar-item-btn { box-shadow: inset 2px 0 0 var(--primary-400); }
    .fi-sidebar-nav { row-gap: calc(var(--spacing) * 5); }
</style>
