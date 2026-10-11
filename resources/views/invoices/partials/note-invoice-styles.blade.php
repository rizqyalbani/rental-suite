<style>
    @page {
        margin: 8mm 6mm;
        size: A4 portrait;
    }
    * { box-sizing: border-box; }
    .invoice-note-root {
        margin: 0;
        padding: 0;
        font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
        color: #111827;
        font-size: 17px;
        line-height: 1.45;
    }
    .invoice-note-root .note-paper {
        width: 100%;
        max-width: 118mm;
        margin: 0 auto;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 18px 20px 20px;
    }
    .invoice-note-root .header {
        text-align: center;
        margin-bottom: 4px;
    }
    .invoice-note-root .title {
        font-size: 34px;
        margin: 0;
        letter-spacing: 0.5px;
        font-weight: 800;
        color: var(--store-accent, #166534);
    }
    .invoice-note-root .brand {
        margin-top: 6px;
        color: #0f172a;
        font-size: 18px;
        font-weight: 700;
        letter-spacing: 0.02em;
    }
    .invoice-note-root .meta {
        color: #64748b;
        font-size: 15px;
        margin-top: 6px;
        font-weight: 400;
    }
    .invoice-note-root .block {
        margin-top: 14px;
        padding-top: 12px;
        border-top: 1px dashed #cbd5e1;
    }
    .invoice-note-root .label {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #64748b;
        margin-bottom: 8px;
        font-weight: 600;
    }
    .invoice-note-root .value {
        font-size: 18px;
        font-weight: 700;
        color: #0f172a;
    }
    .invoice-note-root .value-meta {
        margin-top: 4px;
        font-size: 15px;
        font-weight: 400;
        color: #64748b;
    }
    .invoice-note-root table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 8px;
    }
    .invoice-note-root th,
    .invoice-note-root td {
        text-align: left;
        padding: 10px 4px;
        border-bottom: 1px solid #e5e7eb;
        font-size: 16px;
    }
    .invoice-note-root th {
        color: #475569;
        font-size: 13px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }
    .invoice-note-root .row-detail td {
        font-size: 15px;
        color: #475569;
        font-weight: 500;
        padding-top: 8px;
        padding-bottom: 8px;
    }
    .invoice-note-root .row-detail td.text-right {
        color: #475569;
    }
    .invoice-note-root .summary-row td {
        border-bottom: 1px solid #e5e7eb;
        padding-top: 14px;
        padding-bottom: 10px;
        font-weight: 700;
        font-size: 17px;
        color: #0f172a;
    }
    .invoice-note-root .summary-row td.text-right {
        font-weight: 800;
        font-size: 18px;
        color: var(--store-accent, #166534);
    }
    .invoice-note-root .summary-row.total-emphasis td {
        border-bottom: 0;
    }
    .invoice-note-root .text-right { text-align: right; }
</style>
