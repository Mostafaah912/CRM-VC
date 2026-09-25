/** Persian digits with thousands separators; the stored value is an integer, so no rounding happens. */
export function formatNumber(value: number): string {
    return value.toLocaleString('fa-IR');
}

/** Money is int Toman everywhere in this app (CLAUDE.md §2). */
export function formatToman(value: number): string {
    return `${formatNumber(value)} تومان`;
}
