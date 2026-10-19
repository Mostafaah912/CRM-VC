<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Dto\AiResponse;
use App\Modules\Ai\Exceptions\AiProviderException;
use Closure;

/**
 * THE AiProvider the container hands out (AppServiceProvider): every real call goes through BudgetGuard, with no
 * way around it (tests/Arch/AiBudgetBoundaryTest.php). reserve → call → record usage → release. A provider failure
 * frees the reservation and records nothing — EXCEPT an unusable answer that carries real usage (AiProviderException::$billed), which is
 * recorded: that spend happened.
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
            try {
                $response = $this->provider()->generate($request);
            } catch (AiProviderException $e) {
                // The provider ANSWERED but the answer was unusable (cut off, blocked, malformed): the tokens were still charged, so the
                // ledger records them. No usage (timeout before an answer, 4xx/5xx) records nothing.
                if ($e->billed !== null) {
                    $this->usage->record($e->billed);
                }

                throw $e;
            }

            $this->usage->record($response);
        } finally {
            $this->guard->release($reservation);   // every path: success, billed failure, unbilled failure
        }

        $this->guard->warnIfNeeded();

        return $response;
    }

    private function provider(): AiProvider
    {
        return $this->resolved ??= $this->inner instanceof Closure ? ($this->inner)() : $this->inner;
    }
}
