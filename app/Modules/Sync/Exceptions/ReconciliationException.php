<?php

declare(strict_types=1);

namespace App\Modules\Sync\Exceptions;

/** Woo's answer for a month cannot be trusted as a count: no X-WP-Total, or a total that disagrees with the orders read. Counts only — never an order. */
final class ReconciliationException extends WooException {}
