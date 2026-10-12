<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools;

use RuntimeException;

/** A controlled "the model sent bad arguments" signal: its message is safe to hand back to the model (it never echoes the input). */
final class ToolArgumentException extends RuntimeException {}
