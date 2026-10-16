<?php

declare(strict_types=1);

namespace App\Modules\Ai\Context;

use RuntimeException;

/** Even the irreducible core (freshness, KPIs, anomalies at their floor) does not fit AI_MAX_CONTEXT_KB. */
final class ContextTooLargeException extends RuntimeException {}
