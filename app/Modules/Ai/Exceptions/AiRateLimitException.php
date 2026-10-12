<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

/** The provider rate-limited the request (HTTP 429 / quota). */
final class AiRateLimitException extends AiProviderException {}
