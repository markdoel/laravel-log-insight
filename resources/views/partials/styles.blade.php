<style>
    :root {
        color-scheme: light dark;
        --li-bg: #f6f7f9;
        --li-surface: #ffffff;
        --li-border: #dfe3e8;
        --li-text: #1c2128;
        --li-muted: #5b6573;
        --li-accent: #c2410c;
        --li-code-bg: #f0f2f5;
        --li-low-bg: #e6f4ea; --li-low-fg: #1e6b34;
        --li-medium-bg: #fff4d6; --li-medium-fg: #7a5200;
        --li-high-bg: #ffe5d4; --li-high-fg: #9a3a07;
        --li-critical-bg: #fde2e2; --li-critical-fg: #a01b1b;
        --li-neutral-bg: #eceff3; --li-neutral-fg: #3d4651;
    }

    @media (prefers-color-scheme: dark) {
        :root {
            --li-bg: #0f1216;
            --li-surface: #171b21;
            --li-border: #2c333d;
            --li-text: #e4e8ee;
            --li-muted: #9aa4b2;
            --li-accent: #fb923c;
            --li-code-bg: #20262e;
            --li-low-bg: #15321f; --li-low-fg: #8fdca6;
            --li-medium-bg: #3a2e0c; --li-medium-fg: #f5d27a;
            --li-high-bg: #3d2210; --li-high-fg: #fdba8c;
            --li-critical-bg: #421616; --li-critical-fg: #fca5a5;
            --li-neutral-bg: #252b34; --li-neutral-fg: #c3cad4;
        }
    }

    *, *::before, *::after { box-sizing: border-box; }

    body {
        margin: 0;
        background: var(--li-bg);
        color: var(--li-text);
        font: 14px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    code, pre, .li-code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; }
    code { background: var(--li-code-bg); padding: 1px 4px; border-radius: 4px; }
    pre { background: var(--li-code-bg); padding: 12px; border-radius: 6px; overflow-x: auto; }
    pre code { padding: 0; background: none; }
    h1 { font-size: 18px; margin: 0 0 8px; }

    .li-container { max-width: 1280px; margin: 0 auto; padding: 16px; }
    .li-header { background: var(--li-surface); border-bottom: 1px solid var(--li-border); }
    .li-header-inner { display: flex; align-items: baseline; gap: 12px; padding-top: 12px; padding-bottom: 12px; }
    .li-brand { color: var(--li-accent); font-weight: 700; font-size: 16px; text-decoration: none; }
    .li-muted { color: var(--li-muted); }
    .li-nowrap { white-space: nowrap; }
    .li-stack { display: flex; flex-direction: column; gap: 16px; }

    .li-card { background: var(--li-surface); border: 1px solid var(--li-border); border-radius: 8px; }
    .li-notice { padding: 16px; }
    .li-notice-error { border-color: var(--li-critical-fg); color: var(--li-critical-fg); }

    .li-filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px 16px; padding: 12px 16px; }
    .li-field { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--li-muted); }
    .li-field select {
        min-width: 160px; padding: 6px 8px; font: inherit; font-size: 14px;
        color: var(--li-text); background: var(--li-surface);
        border: 1px solid var(--li-border); border-radius: 6px;
    }
    .li-loading { align-self: center; }

    .li-button {
        display: inline-block; padding: 6px 12px; font: inherit;
        color: var(--li-text); background: var(--li-surface);
        border: 1px solid var(--li-border); border-radius: 6px; cursor: pointer;
    }
    .li-button:hover:not([aria-disabled]) { border-color: var(--li-accent); }
    .li-button[aria-disabled] { color: var(--li-muted); cursor: default; opacity: .6; }
    :focus-visible { outline: 2px solid var(--li-accent); outline-offset: 2px; }

    .li-table-wrap { overflow-x: auto; }
    .li-table { width: 100%; border-collapse: collapse; }
    .li-table th, .li-table td { padding: 8px 12px; text-align: left; vertical-align: top; border-bottom: 1px solid var(--li-border); }
    .li-table th { font-size: 12px; font-weight: 600; color: var(--li-muted); white-space: nowrap; }
    .li-table tbody tr:last-child td { border-bottom: 0; }
    .li-table tbody tr:hover { background: var(--li-code-bg); }
    .li-num { text-align: right !important; font-variant-numeric: tabular-nums; }
    .li-message { min-width: 280px; overflow-wrap: anywhere; }
    .li-code { min-width: 200px; overflow-wrap: break-word; }
    .li-level { font-weight: 600; }

    .li-badge {
        display: inline-block; padding: 1px 8px; border-radius: 999px;
        font-size: 12px; font-weight: 600; white-space: nowrap;
        background: var(--li-neutral-bg); color: var(--li-neutral-fg);
    }
    .li-severity-low { background: var(--li-low-bg); color: var(--li-low-fg); }
    .li-severity-medium { background: var(--li-medium-bg); color: var(--li-medium-fg); }
    .li-severity-high { background: var(--li-high-bg); color: var(--li-high-fg); }
    .li-severity-critical { background: var(--li-critical-bg); color: var(--li-critical-fg); }
    .li-status-failed { background: var(--li-critical-bg); color: var(--li-critical-fg); }
    .li-status-analyzed { background: var(--li-low-bg); color: var(--li-low-fg); }

    .li-footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; }
    .li-pagination { display: flex; align-items: center; gap: 12px; }
</style>
