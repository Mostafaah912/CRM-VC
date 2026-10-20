<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Drivers\AnthropicDriver;
use App\Modules\Ai\Drivers\GeminiDriver;
use App\Modules\Ai\Drivers\OpenAiDriver;
use App\Modules\Ai\Enums\AiDriver;
use App\Modules\Ai\Exceptions\AiConfigurationException;

/** Picks the driver named by config('ai.provider'). The only code allowed to know the concrete drivers. */
final class AiProviderFactory
{
    public function __construct(private readonly AiCostCalculator $costs) {}

    public function make(?string $provider = null): AiProvider
    {
        $name = $provider ?? (string) config('ai.provider');
        $driver = AiDriver::tryFrom($name)
            ?? throw new AiConfigurationException("Unknown AI provider [{$name}]: use one of ".implode(', ', array_column(AiDriver::cases(), 'value')).'.', $name);

        $settings = (array) config("ai.providers.{$driver->value}");
        $common = [
            (string) ($settings['api_key'] ?? ''),
            (string) ($settings['base_url'] ?? ''),
            (string) config('ai.model'),
            (int) config('ai.timeout'),
            (int) config('ai.max_retries'),
            array_map(intval(...), (array) config('ai.retry_backoff_seconds')),
            $this->costs,
        ];

        return match ($driver) {
            AiDriver::Gemini => new GeminiDriver(...$common),
            AiDriver::Anthropic => new AnthropicDriver(...$common, version: (string) ($settings['version'] ?? '2023-06-01')),
            AiDriver::OpenAi => new OpenAiDriver(...$common),
        };
    }
}
