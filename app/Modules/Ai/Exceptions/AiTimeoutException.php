<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

/** The request timed out or the connection failed. */
final class AiTimeoutException extends AiProviderException {}
