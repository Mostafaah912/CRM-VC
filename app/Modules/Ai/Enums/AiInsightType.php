<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

enum AiInsightType: string
{
    case DailyBrief = 'daily_brief';
    case WeeklyReview = 'weekly_review';
    case Explain = 'explain';
    case Anomaly = 'anomaly';
    case Customer = 'customer';
}
