/** Props of the P6-08 standalone Cohort/Retention/Affinity pages. */

import type {
    DashboardAffinityPair,
    DashboardCohortRow,
} from '@/types/dashboard';

export type CohortPageData = DashboardCohortRow[];

export type RetentionWindow = {
    days: number;
    mature_customers: number;
    returned_customers: number;
    retention_rate: number | null;
    insufficient_data: boolean;
};

export type RetentionPageData = {
    repeat_purchase_rate: {
        eligible_customers: number;
        repeat_customers: number;
        rate: number | null;
        insufficient_data: boolean;
    };
    returning_revenue_share: {
        total_revenue: number;
        returning_revenue: number;
        share: number | null;
        insufficient_data: boolean;
    };
    retention: RetentionWindow[];
};

export type AffinityLevel = 'category' | 'product' | 'variation' | 'basket';

export type AffinityPageData = Record<AffinityLevel, DashboardAffinityPair[]>;
