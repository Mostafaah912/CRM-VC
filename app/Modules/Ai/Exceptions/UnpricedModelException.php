<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

/** Fail-closed: a budget cannot be enforced for a model with no price, so the call is refused. */
final class UnpricedModelException extends AiProviderException {}
