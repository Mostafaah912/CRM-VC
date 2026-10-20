<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Ai\Services\AnalystPageService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Segments\Services\CustomerSegmentSummaryService;

/**
 * P7-09b: the AGGREGATE panel of the Customer 360 page — the customer's segments with segment-level numbers, and a pointer to the latest analyst
 * insight. It composes Segments and Ai through their public Services (it lives in app/Support: no single module's dependency table covers both, the
 * same reasoning as HealthCheckService / MetricsGuideService). It makes NO AI call and sends nothing anywhere; no per-customer value (name, phone,
 * order, metric) is in its output — only which segments the customer is in and those segments' own aggregates. A null part means the viewer lacks the
 * permission for it (segments.view / ai.view), not "empty".
 */
final class CustomerAnalystPanelService
{
    public function __construct(
        private readonly CustomerSegmentSummaryService $segments,
        private readonly AnalystPageService $analyst,
    ) {}

    /** @return array{customer_id: int, segments: list<array<string, mixed>>|null, latest_insight: array<string, mixed>|null} */
    public function for(int $customerId, bool $canSeeSegments, bool $canSeeInsight): array
    {
        Customer::query()->findOrFail($customerId);   // an unknown or soft-deleted customer is a 404, like the page itself

        return [
            'customer_id' => $customerId,
            'segments' => $canSeeSegments ? $this->segments->forCustomer($customerId) : null,
            'latest_insight' => $canSeeInsight ? $this->analyst->latestInsightPointer() : null,
        ];
    }
}
