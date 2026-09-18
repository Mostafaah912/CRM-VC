<?php

declare(strict_types=1);

namespace App\Modules\Sync\Exceptions;

/** config/woo.php is incomplete or unsafe. Messages name the setting, never its value. */
final class WooConfigurationException extends WooException {}
