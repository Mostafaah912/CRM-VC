<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Dto\AnomalyReport;

/** Where get_anomalies reads from. Implemented by Anomaly\AnomalyDetector (pure statistics, P7-05). */
interface AnomalySource
{
    public function report(int $days): AnomalyReport;
}
