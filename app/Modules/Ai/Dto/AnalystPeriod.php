<?php

declare(strict_types=1);

namespace App\Modules\Ai\Dto;

/** The calendar period an insight is ABOUT (Asia/Tehran days, inclusive, ISO). `scope` goes into ai_insights.scope (varchar(40)). */
final readonly class AnalystPeriod
{
    public function __construct(
        public string $start,
        public string $end,
        public string $scope,
        public string $jalaliStart,
        public string $jalaliEnd,
    ) {}
}
