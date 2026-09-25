<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\WooWebhookRequest;
use App\Modules\Sync\Services\WooWebhookService;
use Illuminate\Http\JsonResponse;

/**
 * POST /webhooks/woo: the request is already authenticated (WooWebhookRequest); hand its topic and delivery id to the
 * service and answer 200 at once (PRD §10: never process inline). The body is not touched.
 */
final class WooWebhookController extends Controller
{
    public function __invoke(WooWebhookRequest $request, WooWebhookService $webhooks): JsonResponse
    {
        $outcome = $webhooks->receive($request->topicHeader(), $request->deliveryIdHeader());

        return response()->json(['status' => $outcome->value]);
    }
}
