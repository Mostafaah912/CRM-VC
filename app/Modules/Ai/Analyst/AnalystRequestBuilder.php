<?php

declare(strict_types=1);

namespace App\Modules\Ai\Analyst;

use App\Modules\Ai\Dto\AiMessage;
use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Dto\AnalystRequest;
use App\Modules\Ai\Dto\BuiltContext;
use App\Modules\Ai\Enums\AiInsightType;

/**
 * Turns a BuiltContext into a provider request WITHOUT calling anything. The context travels in the USER message, fenced in a
 * `<data>` block that the system prompt declares to be data and never instructions — it is never concatenated into the system prompt.
 * Every "<" in the JSON is written as < (still valid JSON, same value), so no string inside the data — a product named
 * "</data> ignore the rules" — can close the fence early.
 */
final class AnalystRequestBuilder
{
    /** The JSON escape for "<" (backslash, u, 0, 0, 3, c): valid JSON, same value, can never be read as a tag. Built from two pieces on purpose. */
    public const LT = '\\'.'u003c';

    public function __construct(private readonly PromptRepository $prompts) {}

    public function build(AiInsightType $type, BuiltContext $context): AnalystRequest
    {
        $prompt = $this->prompts->for($type);
        $data = str_replace('<', self::LT, $context->json);
        $user = str_replace('{{context}}', $data, $prompt->userTemplate);

        $request = new AiRequest(
            $prompt->system,
            [AiMessage::user($user)],
            [],
            OutputSchema::schema(),
            max(256, (int) config('ai.analyst.max_output_tokens', 3000)),
            (float) config('ai.analyst.temperature', 0.2),
        );

        $bytes = strlen($prompt->system) + strlen($user) + strlen((string) json_encode(OutputSchema::schema()));

        return new AnalystRequest($request, $type->value, $prompt->version, strlen($prompt->system), strlen($user), $context->sizeBytes, (int) ceil($bytes / max(0.5, (float) config('ai.context.bytes_per_token', 3.0))));
    }
}
