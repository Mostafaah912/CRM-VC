<?php

declare(strict_types=1);

namespace App\Modules\Sync\Exceptions;

use LogicException;

/**
 * FakeWooClient was asked for an exchange nobody recorded. This is a bug in the TEST, not a
 * WooCommerce failure, so it deliberately is NOT a WooException: sync code that catches
 * WooException to retry or log must never swallow it. Messages name filter keys, never values.
 */
final class WooFixtureNotFoundException extends LogicException {}
