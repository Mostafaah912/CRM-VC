<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Dto\AiResponse;
use Throwable;

/**
 * THE AiProvider the container hands out (AppServiceProvider): every real call goes through BudgetGuard, with no
 * way around it (tests/Arch/AiBudgetBoundaryTest.php). reserve → call → record usage → release. A provider failure
 * records nothing and frees the reservation.
 */
final class BudgetedAiProvider implements AiProvider
{
    public function __construct(
        private readonly AiProvider $inner,
        private readonly BudgetGuard $guard,
        private readonly AiUsageService $usage,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        $reservation = $this->guard->reserve($request, (string) config('ai.model'));

        try {
            $response = $this->inner->generate($request);
            $this->usage->record($response);
        } catch (Throwable $e) {
            $this->guard->release($reservation);

            throw $e;
        }

        $this->guard->release($reservation);
        $this->guard->warnIfNeeded();

        return $response;
    }
}
