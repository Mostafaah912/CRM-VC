<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Backup;

use RuntimeException;

/** A backup or restore-test step failed. The message is safe to log/alert: it never carries a secret. */
final class BackupFailedException extends RuntimeException {}
