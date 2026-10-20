<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

/** The provider refused to answer (safety / content filter). */
final class AiContentBlockedException extends AiProviderException {}
