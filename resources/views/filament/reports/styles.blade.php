<style>
    .fin-statement { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
    .fin-statement th { text-align: left; font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.5rem; border-bottom: 2px solid var(--gray-300, #d4d4d8); color: var(--gray-500, #71717a); }
    .fin-statement td { padding: 0.3rem 0.5rem; border-bottom: 1px solid var(--gray-100, #f4f4f5); vertical-align: top; }
    .fin-statement .fin-num { text-align: right; white-space: nowrap; font-family: var(--mono-font-family, ui-monospace), ui-monospace, monospace; font-variant-numeric: tabular-nums; width: 13rem; }
    .fin-statement .fin-negative { color: var(--danger-600, #dc2626); }
    .fin-statement .fin-ratio { display: block; font-size: 0.6875rem; color: var(--gray-500, #71717a); }
    .fin-statement tr.fin-header td { font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.04em; padding-top: 0.9rem; border-bottom: 1px solid var(--gray-300, #d4d4d8); }
    .fin-statement tr.fin-subtotal td { font-weight: 600; border-top: 1px solid var(--gray-300, #d4d4d8); }
    .fin-statement tr.fin-total td { font-weight: 700; font-size: 0.875rem; background: var(--gray-50, #fafafa); border-top: 1.5px solid var(--gray-400, #a1a1aa); border-bottom: 1.5px solid var(--gray-400, #a1a1aa); }
    .fin-statement tr.fin-note td { font-size: 0.75rem; font-style: italic; color: var(--gray-500, #71717a); }
    .fin-statement .fin-change { color: var(--gray-600, #52525b); }
    .fin-notes { margin-top: 0.75rem; padding-left: 1.1rem; list-style: disc; font-size: 0.75rem; color: var(--gray-500, #71717a); }
    .dark .fin-statement td { border-bottom-color: rgb(255 255 255 / 0.06); }
    .dark .fin-statement th, .dark .fin-statement tr.fin-header td, .dark .fin-statement tr.fin-subtotal td { border-color: rgb(255 255 255 / 0.15); }
    .dark .fin-statement tr.fin-total td { background: rgb(255 255 255 / 0.04); border-color: rgb(255 255 255 / 0.25); }
    .fin-scroll { overflow-x: auto; }
</style>
