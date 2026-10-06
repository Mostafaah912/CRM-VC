/**
 * The one place a number is ever formatted in this app (P6-12) — Latin digits, a comma thousands
 * separator, never a Persian/Arabic-Indic digit and never the Arabic percent-sign glyph: the stored
 * value is an integer, so no rounding happens. `'en-US'` is deliberate, not a mistake — it is the locale whose
 * grouping (comma, Latin digits) matches what every page must show; the Persian words around it are what
 * actually makes the page Persian (CLAUDE.md §2/D10).
 */
export function formatNumber(value: number): string {
    return value.toLocaleString('en-US');
}

/** Money is int Toman everywhere in this app (CLAUDE.md §2). */
export function formatToman(value: number): string {
    return `${formatNumber(value)} تومان`;
}

/** A ratio (0..1) as a whole-number percent — e.g. 0.42 -> `42%`, Latin digits and an ASCII `%`. */
export function formatPercent(ratio: number): string {
    return `${formatNumber(Math.round(ratio * 100))}%`;
}

/** formatPercent(), or `insufficientMessage` when the ratio itself is unknown (too little data to compute one). */
export function formatRate(
    ratio: number | null,
    insufficientMessage = 'داده کافی نیست',
): string {
    return ratio === null ? insufficientMessage : formatPercent(ratio);
}

/**
 * `customer_metrics.cohort_month` ("1405-02") display-only, as "1405/02" — the unified `/` separator
 * every other date/period on the page uses (P6-11/P6-12). Never used for the value a drill-down request
 * actually sends: DrillRequest's `cohort_month` parameter is validated against the dashed form, which
 * this does not touch.
 */
export function formatCohortMonth(value: string): string {
    return value.replace('-', '/');
}
