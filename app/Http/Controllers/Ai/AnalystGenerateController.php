<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AnalystGenerateRequest;
use App\Modules\Ai\Exceptions\AnalystNotReadyException;
use App\Modules\Ai\Exceptions\ManualRequestThrottledException;
use App\Modules\Ai\Services\ManualGenerationService;
use App\Modules\Ai\Support\AnalystMessages;
use Illuminate\Http\JsonResponse;

/**
 * P7-09a: «تولید دستی» — behind ai.request. Validates (FormRequest), asks ManualGenerationService to queue the work, returns at once:
 * 202 + the state to poll, or a refusal with a Persian sentence and a stable code (422 not ready / 429 throttled). The generation itself
 * is a queued job (it takes 20-45 s); nothing here calls a provider.
 */
final class AnalystGenerateController extends Controller
{
    public function __invoke(AnalystGenerateRequest $request, ManualGenerationService $manual): JsonResponse
    {
        try {
            $state = $manual->request($request->type(), $request->actor(), $request->force());
        } catch (AnalystNotReadyException|ManualRequestThrottledException $e) {
            $m = AnalystMessages::forException($e);

            return response()->json(['code' => $m['code'], 'message' => $m['message']], AnalystMessages::httpStatus($m['code']));
        }

        return response()->json($state, 202);
    }
}
