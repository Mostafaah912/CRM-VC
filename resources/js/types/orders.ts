/** Props of the P3-06 order list and detail pages. */

export type OrderListRow = {
    id: number;
    woo_order_id: number;
    status: string;
    /** int Toman, exactly as stored. */
    total: number;
    is_realized: boolean;
    needs_phone_review: boolean;
    ordered_at_jalali: string;
    ordered_at_iso: string;
    /** null when the order has no customer (needs_phone_review). */
    customer_id: number | null;
    customer_display_name: string | null;
};

export type OrderListFilters = {
    woo_order_id: number | null;
    status: string | null;
    is_realized: boolean | null;
    needs_phone_review: boolean | null;
    ordered_from: string | null;
    ordered_to: string | null;
};

export type OrderListOptions = {
    statuses: string[];
};

export type OrderItemRow = {
    name: string;
    sku: string | null;
    qty: number;
    unit_price: number;
    line_total: number;
};

export type OrderCustomer = {
    id: number;
    display_name: string | null;
    /** Always the masked form; the full number comes only from the audited reveal endpoint. */
    phone_masked: string;
    profile_url: string;
};

export type OrderDetail = {
    id: number;
    woo_order_id: number;
    status: string;
    total: number;
    subtotal: number;
    discount_total: number;
    shipping_total: number;
    tax_total: number;
    refunded_total: number;
    is_realized: boolean;
    is_fully_refunded: boolean;
    needs_phone_review: boolean;
    ordered_at_jalali: string;
    ordered_at_iso: string;
    items: OrderItemRow[];
    customer: OrderCustomer | null;
};
