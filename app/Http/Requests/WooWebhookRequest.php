<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Modules\Sync\Enums\WebhookVerdict;
use App\Modules\Sync\Services\WooWebhookVerifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

/**
 * Validation of a Woo webhook delivery is authentication: WooWebhookVerifier checks the secret, the source IP and the
 * HMAC of the RAW body (`getContent()`, never the decoded input). Anything else is a 401 with the same answer for every
 * reason; the reason and the source IP go to the log, never the body, the signature or the secret. The payload is neither
 * validated nor read: `validationData` is empty on purpose and only three headers are used. (Laravel itself decodes a
 * JSON-typed body into the request's input bag when it builds the request — before any middleware, in memory only; that
 * bag is never read here.)
 */
final class WooWebhookRequest extends FormRequest
{
    private WebhookVerdict $verdict = WebhookVerdict::SignatureInvalid;

    public function authorize(): bool
    {
        $this->verdict = app(WooWebhookVerifier::class)->verify($this->getContent(), $this->headers->get('X-WC-Webhook-Signature'), $this->ip());

        return $this->verdict === WebhookVerdict::Authentic;
    }

    /** @return array<string, never> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, never> the payload is never handed to the validator */
    public function validationData(): array
    {
        return [];
    }

    public function topicHeader(): ?string
    {
        return $this->headers->get('X-WC-Webhook-Topic');
    }

    public function deliveryIdHeader(): ?string
    {
        return $this->headers->get('X-WC-Webhook-Delivery-ID');
    }

    protected function failedAuthorization(): never
    {
        Log::warning('Woo webhook rejected', ['reason' => $this->verdict->value, 'ip' => $this->ip()]);

        throw new HttpResponseException(response()->json(['message' => 'Unauthorized'], 401));
    }
}
