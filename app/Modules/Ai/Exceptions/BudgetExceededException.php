<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

/** The call was refused BEFORE any provider was contacted: it would (or could) push the month over its cap. */
final class BudgetExceededException extends AiProviderException {}
