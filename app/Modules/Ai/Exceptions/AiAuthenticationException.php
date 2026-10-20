<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

/** The provider rejected the credentials (HTTP 401/403). */
final class AiAuthenticationException extends AiProviderException {}
