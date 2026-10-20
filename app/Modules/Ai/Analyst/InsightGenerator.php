<?php

declare(strict_types=1);

namespace App\Modules\Ai\Analyst;

use App\Modules\Ai\Dto\AiMessage;
use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Dto\AiResponse;
use App\Modules\Ai\Dto\BuiltContext;
use App\Modules\Ai\Dto\GeneratedInsight;
use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Ai\Exceptions\AiResponseInvalidException;
use App\Modules\Ai\Services\AiProvider;
use App\Modules\Ai\Support\Decimal;

/**
 * context -> validated insight. Order: cache lookup (a hit calls NO provider and spends NO budget; `force` bypasses it) -> request ->
 * provider -> strict validation + grounding -> on failure exactly ONE corrective retry (a second provider call through the same
 * AiProvider, hence through BudgetGuard) -> AiResponseInvalidException if still invalid. The retry message lists the validator's
 * findings (paths and rules), never the model's own text. Persisting the result is P7-08.
 */
final class InsightGenerator
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly AnalystRequestBuilder $requests,
        private readonly OutputValidator $validator,
        private readonly ResponseCache $cache,
        private readonly PromptRepository $prompts,
    ) {}

    public function generate(AiInsightType $type, BuiltContext $context, bool $force = false): GeneratedInsight
    {
        $version = $this->prompts->for($type)->version;
        $key = $this->cache->key($type->value, $version, (string) config('ai.model'), $context->json);

        if (! $force && ($cached = $this->cache->lookup($key)) !== null) {
            return new GeneratedInsight($cached, 'cache', $type->value, $version, $key, 0, 0, [], 0, 0, '0.00000', null, 0);
        }

        $analyst = $this->requests->build($type, $context);
        /** @var array<string, mixed> $decodedContext */
        $decodedContext = json_decode($context->json, true) ?? [];
        $request = $analyst->request;
        $responses = [];
        $errors = [];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = $this->provider->generate($request);
            $responses[] = $response;
            $result = $this->check($response, $decodedContext);

            if ($result['payload'] !== null) {
                return $this->done($result['payload'], $type->value, $version, $key, $attempt, $result['redactions'], $responses);
            }

            $errors = $result['errors'];
            $request = new AiRequest(
                $request->systemPrompt,
                [...$analyst->request->messages, AiMessage::assistant('(previous answer rejected)'), AiMessage::user($this->feedback($errors))],
                [],
                $request->responseSchema,
                $request->maxOutputTokens,
                $request->temperature,
            );
        }

        throw new AiResponseInvalidException($errors, 2);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{payload: array<string, mixed>|null, errors: list<string>, redactions: int}
     */
    private function check(AiResponse $response, array $context): array
    {
        if ($response->hasToolCalls()) {
            return ['payload' => null, 'errors' => ['$: tool calls are not allowed here; answer with the JSON object only'], 'redactions' => 0];
        }

        $data = $response->json ?? (is_string($response->text) ? json_decode($response->text, true) : null);

        if (! is_array($data) || array_is_list($data)) {
            return ['payload' => null, 'errors' => ['$: the answer must be one JSON object'], 'redactions' => 0];
        }

        return $this->validator->validate($data, $context);
    }

    /** @param list<string> $errors */
    private function feedback(array $errors): string
    {
        return "Your previous answer was rejected. Fix ONLY these problems and answer again with the complete JSON object, nothing else:\n- ".implode("\n- ", $errors);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<AiResponse>  $responses
     */
    private function done(array $payload, string $type, string $version, string $key, int $attempts, int $redactions, array $responses): GeneratedInsight
    {
        $cost = '0';

        foreach ($responses as $r) {
            $cost = bcadd($cost, Decimal::of($r->costUsd), 5);
        }

        return new GeneratedInsight(
            $payload, 'model', $type, $version, $key, $attempts, $redactions, $responses,
            array_sum(array_map(fn (AiResponse $r) => $r->inputTokens, $responses)),
            array_sum(array_map(fn (AiResponse $r) => $r->outputTokens, $responses)),
            $cost, $responses === [] ? null : $responses[array_key_last($responses)]->model, array_sum(array_map(fn (AiResponse $r) => $r->durationMs, $responses)),
        );
    }
}
