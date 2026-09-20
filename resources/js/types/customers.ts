/** Props of the P3-01 customer list. The phone is already masked (or not) by the server; the page never decides that. */

export type CustomerRow = {
    id: number;
    display_name: string | null;
    phone: string;
    phone_is_masked: boolean;
    status: string;
    lifecycle_stage: string;
    province: string | null;
    city: string | null;
    first_seen_at: string | null;
    needs_review: boolean;
};

export type CustomerFilters = {
    search: string | null;
    status: string | null;
    lifecycle_stage: string | null;
    province: string | null;
    city: string | null;
    needs_review: boolean | null;
    first_seen_from: string | null;
    first_seen_to: string | null;
};

export type CustomerOptions = {
    statuses: string[];
    lifecycle_stages: string[];
    provinces: string[];
    cities: string[];
};
