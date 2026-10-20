<?php

declare(strict_types=1);

namespace App\Modules\Ai\Context;

use RuntimeException;

/** A context was about to leave with something that looks like customer data. Names the JSON path and the kind — never the value. */
final class ContextPiiException extends RuntimeException {}
