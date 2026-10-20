<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools;

use App\Modules\Ai\Tools\Tools\GetAnomaliesTool;
use App\Modules\Ai\Tools\Tools\GetChurnReportTool;
use App\Modules\Ai\Tools\Tools\GetCohortReportTool;
use App\Modules\Ai\Tools\Tools\GetCustomerMetricsDistributionTool;
use App\Modules\Ai\Tools\Tools\GetCustomerSummaryTool;
use App\Modules\Ai\Tools\Tools\GetDashboardMetricsTool;
use App\Modules\Ai\Tools\Tools\GetProductAffinityTool;
use App\Modules\Ai\Tools\Tools\GetRetentionReportTool;
use App\Modules\Ai\Tools\Tools\GetRfmDistributionTool;
use App\Modules\Ai\Tools\Tools\GetSalesTrendTool;
use App\Modules\Ai\Tools\Tools\GetSegmentSummaryTool;
use App\Modules\Ai\Tools\Tools\GetTopProductsTool;

/** The 12 tools of PRD §19, in PRD order. Adding a tool here is the only way it reaches the model. */
final class ToolCatalog
{
    /** @var list<class-string<AiTool>> */
    public const TOOLS = [
        GetDashboardMetricsTool::class,
        GetSalesTrendTool::class,
        GetRfmDistributionTool::class,
        GetChurnReportTool::class,
        GetRetentionReportTool::class,
        GetCohortReportTool::class,
        GetProductAffinityTool::class,
        GetTopProductsTool::class,
        GetSegmentSummaryTool::class,
        GetCustomerMetricsDistributionTool::class,
        GetAnomaliesTool::class,
        GetCustomerSummaryTool::class,
    ];
}
