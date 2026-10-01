/**
 * P6-12: an id with no thousands separator and no digit conversion — `#1234`, never a grouped or
 * Persian-digit form — plus the
 * name next to it when one is known (Catalog's own product/category name, looked up backend-side). The
 * id is wrapped in `<bdi dir="ltr">` so `#` + digits stay in that order inside surrounding RTL text; the
 * name, if any, is genuine Persian text and needs no isolation of its own.
 */
export function EntityId({ id, name }: { id: number; name?: string | null }) {
    return (
        <span>
            <bdi dir="ltr">#{id}</bdi>
            {name != null && <> ({name})</>}
        </span>
    );
}
