/** Props of the P4-08 RFM page (PRD §12). */

/** Every rfm_segment (PRD §12, 8 segments) plus 'none' for a not-yet-eligible customer. */
export type RfmSegmentCounts = {
    champion: number;
    loyal: number;
    promising: number;
    new_customer: number;
    at_risk: number;
    cant_lose: number;
    hibernating: number;
    lost: number;
    none: number;
};

/** Keys '1'..'5' — a customer's count at each score, for one of r/f/m. */
export type ScoreDistribution = Record<string, number>;

export type RfmTopChampion = {
    /** Never a name or phone — the page links to Customer 360, which shows the rest to a permitted viewer. */
    customer_id: number;
    total_revenue: number;
    rfm_score: string | null;
};

export type RfmPageData = {
    segments: RfmSegmentCounts;
    scores: {
        r: ScoreDistribution;
        f: ScoreDistribution;
        m: ScoreDistribution;
    };
    /** null when no metric run has ever completed. */
    latest_run: {
        computed_at: string | null;
        computed_at_iso: string | null;
        status: string | null;
    } | null;
    top_champions: RfmTopChampion[];
};
