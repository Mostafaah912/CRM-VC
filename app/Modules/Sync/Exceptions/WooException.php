<?php

declare(strict_types=1);

namespace App\Modules\Sync\Exceptions;

use RuntimeException;

/** Base of everything the Woo client raises — a failure is never turned into a fake success. */
abstract class WooException extends RuntimeException {}
