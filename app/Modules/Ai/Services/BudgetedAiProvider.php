<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Dto\AiResponse;
use Closure;
use Throwable;

/**
 * THE AiProvider the container hands out (AppServiceProvider): every real call goes through BudgetGuard, with no
 * way around it (tests/Arch/AiBudgetBoundaryTest.php). reserve → call → record usage → release. A provider failure
 * records nothing and frees the reservation.
 */
final class BudgetedAiProvider implements AiProvider
{
    private ?AiProvider $resolved = null;

    /**
     * @param  AiProvider|Closure(): AiProvider  $inner  a Closure is called on the FIRST generate(), never at construction: building the real
     *                                                   driver throws when the key/model is missing, and merely RESOLVING the analyst (a job, a command)
     *                                                   must not — "AI is off" and "not configured yet" are reported by AnalystReadiness, not by a crash.
     */
    public function __construct(
        private readonly AiProvider|Closure $inner,
        private readonly BudgetGuard $guard,
        private readonly AiUsageService $usage,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        $reservation = $this->guard->reserve($request, (string) config('ai.model'));

        try {
            $response = $this->provider()->generate($request);
            $this->usage->record($response);
        } catch (Throwable $e) {
            $this->guard->release($reservation);

            throw $e;
        }

        $this->guard->release($reservation);
        $this->guard->warnIfNeeded();

        return $response;
    }

    private function provider(): AiProvider
    {
        return $this->resolved ??= $this->inner instanceof Closure ? ($this->inner)() : $this->inner;
    }
}
