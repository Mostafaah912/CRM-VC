/**
 * P6-12/P6-13: an id with no thousands separator and no digit conversion — `#1234`, never a grouped or
 * Persian-digit form — plus the name next to it when one is known (Catalog's own product/category name,
 * looked up backend-side). The id is wrapped in `<bdi dir="ltr">` so `#` + digits stay in that order
 * inside surrounding RTL text.
 *
 * Stacked, not inline (P6-13): a real product name is often long enough to force a table column wider
 * than its card — the id sits on its own line (always fully visible, never clamped), the name below it
 * wraps and clamps to 2 lines with the full text in a native `title` tooltip, so the row grows at most
 * two lines instead of the column growing past the viewport.
 */
export function EntityId({ id, name }: { id: number; name?: string | null }) {
    return (
        <span className="flex flex-col gap-0.5">
            <bdi dir="ltr" className="text-muted-foreground text-xs">
                #{id}
            </bdi>
            {name != null && (
                <span className="line-clamp-2 break-words" title={name}>
                    {name}
                </span>
            )}
        </span>
    );
}
