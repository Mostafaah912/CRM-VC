<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Dto\AiResponse;
use App\Modules\Ai\Exceptions\AiProviderException;

/**
 * One model call, provider-independent. Only AiGateway (P7-08) and this module's tests may use it:
 * every other module is forbidden by tests/Arch/AiProviderBoundaryTest.php. No budget, cache or schema
 * retry here — those wrap this interface, they are not part of it.
 */
interface AiProvider
{
    /** @throws AiProviderException */
    public function generate(AiRequest $request): AiResponse;
}
