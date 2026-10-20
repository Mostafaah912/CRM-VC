<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Ai\Dto\AiMessage;
use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Services\AiProvider;
use App\Modules\Ai\Services\AnalystReadiness;
use App\Modules\Ai\Support\AiErrorExplainer;
use Illuminate\Console\Command;
use Throwable;

/**
 * `hm:ai-ping` — the cheapest possible real call (one word back) to prove, before anything is scheduled, that the key, the model, its
 * price and the network all work. It goes through the normal budgeted AiProvider, so BudgetGuard prices and records it like any call
 * and an unpriced model is refused. Works even while AI_ENABLED=false (that is the point: verify first, switch on after). It never prints
 * the key and no error path can contain it.
 */
final class AiPingCommand extends Command
{
    protected $signature = 'hm:ai-ping {--max-tokens=64 : output ceiling for the one-word answer (reasoning models may need more than a handful)}';

    protected $description = 'Make one tiny real call to verify the AI key, model, price and network (spends a fraction of a cent)';

    public function handle(AnalystReadiness $readiness): int
    {
        $problem = $readiness->callProblem();

        if ($problem !== null) {
            $this->error("Not ready ({$problem}): ".$readiness->describe($problem));

            return self::FAILURE;
        }

        // Resolved only now: building the real provider throws on a missing key/model, which readiness has just ruled out.
        $provider = app(AiProvider::class);
        $request = new AiRequest('Reply with exactly one word: ok', [AiMessage::user('ping')], [], null, max(8, (int) $this->option('max-tokens')), 0.0);
        $started = hrtime(true);

        try {
            $response = $provider->generate($request);
        } catch (Throwable $e) {
            $this->error('PING FAILED: '.AiErrorExplainer::explain($e));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'PING OK | %s/%s | %d ms | tokens in/out: %d/%d | cost: %s USD | answer: %s',
            $response->provider,
            $response->model,
            (int) ((hrtime(true) - $started) / 1_000_000),
            $response->inputTokens,
            $response->outputTokens,
            $response->costUsd,
            mb_substr(trim((string) $response->text), 0, 40),
        ));

        return self::SUCCESS;
    }
}
