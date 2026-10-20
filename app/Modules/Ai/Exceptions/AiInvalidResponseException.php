<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

/** The provider answered, but the response was unusable (malformed, empty or cut off). */
final class AiInvalidResponseException extends AiProviderException {}
