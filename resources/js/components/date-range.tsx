/**
 * P6-11: the one place a Jalali "from .. to" period is phrased for display — "از X تا Y", never "X .. Y"
 * (which reads backwards once the page's own RTL wraps it). `from`/`to` are pre-formatted Jalali strings
 * from the backend (App\Support\JalaliDate/TehranDateTime, the single source of truth); this component
 * only composes them, it never parses or converts a date itself. Each date is wrapped in `<bdi dir="ltr">`
 * so its own left-to-right token order (YYYY/MM/DD) is isolated from the surrounding RTL text and never
 * flips, regardless of what comes before or after it.
 */
export function DateRange({ from, to }: { from: string; to: string }) {
    return (
        <span dir="rtl">
            از <bdi dir="ltr">{from}</bdi> تا <bdi dir="ltr">{to}</bdi>
        </span>
    );
}
