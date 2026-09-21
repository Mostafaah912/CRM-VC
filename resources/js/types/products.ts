/** Props of the P3-07 product list page. */

export type ProductListRow = {
    id: number;
    woo_product_id: number;
    name: string;
    sku: string | null;
    status: string;
    /** null when the store has no base URL configured. */
    admin_url: string | null;
    total_qty_sold: number;
    /** int Toman, exactly as stored. */
    total_revenue: number;
    order_count: number;
    /** Both null together: the product has never been sold. */
    last_sold_at_jalali: string | null;
    last_sold_at_iso: string | null;
};

export type ProductListFilters = {
    name: string | null;
    sku: string | null;
    status: string | null;
};

export type ProductListOptions = {
    statuses: string[];
};
