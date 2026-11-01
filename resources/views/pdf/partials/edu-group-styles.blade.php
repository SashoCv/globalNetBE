<style>
    @page { margin: 12mm 11mm 16mm; }
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 8.5pt; color: #0f172a; line-height: 1.35; margin: 0; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }

    .footer {
        position: fixed;
        bottom: -10mm;
        left: 0;
        right: 0;
        font-size: 7.5pt;
        color: #64748b;
        border-top: 1px solid #cbd5e1;
        padding-top: 3px;
    }
    .footer td { padding: 0; }
    .footer .page:after { content: counter(page); }

    .group--break { page-break-after: always; }

    .head { border-bottom: 2px solid #0b3f6b; padding-bottom: 8px; margin-bottom: 10px; }
    .head__program { font-size: 11pt; font-weight: bold; color: #0b3f6b; }
    .head__topic { font-size: 8.5pt; color: #475569; margin-top: 2px; }
    .head__group { font-size: 16pt; font-weight: bold; color: #0f172a; margin-top: 6px; }
    .head__staff { font-size: 9pt; color: #475569; }

    .meta { margin-bottom: 12px; }
    .meta td { padding: 2px 6px 2px 0; font-size: 8.5pt; }
    .meta .label { color: #64748b; white-space: nowrap; width: 22mm; }
    .meta .value { font-weight: bold; }

    .list th {
        background: #0b3f6b;
        color: #fff;
        font-weight: bold;
        font-size: 7.5pt;
        padding: 5px 4px;
        border: 1px solid #0b3f6b;
        text-align: left;
    }
    .list td { padding: 4px; border: 1px solid #94a3b8; font-size: 8pt; }
    .list tr { page-break-inside: avoid; }
    .list tr.alt td { background: #f1f5f9; }
    .list .no { width: 8mm; text-align: center; }
    .list .count { width: 22mm; text-align: center; }
    .list .name { width: 32mm; }
    .list .contact { width: 38mm; }
    .list .sign { width: 16mm; }
    .list--bi .no { width: 11mm; }
    .list--bi .count { width: 28mm; }
    .list tr.blank td { height: 22px; }
    .list tr.total td { font-weight: bold; background: #e2e8f0; }
    .list .muted { color: #475569; font-size: 7.5pt; }

    .section { font-size: 9.5pt; font-weight: bold; color: #0b3f6b; margin: 14px 0 6px; }
    .empty { color: #64748b; font-style: italic; padding: 8px 0; }
</style>
