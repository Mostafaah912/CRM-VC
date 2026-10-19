<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Context\ContextPiiException;
use App\Modules\Ai\Context\ContextTooLargeException;
use App\Modules\Ai\Exceptions\AiAuthenticationException;
use App\Modules\Ai\Exceptions\AiConfigurationException;
use App\Modules\Ai\Exceptions\AiInvalidResponseException;
use App\Modules\Ai\Exceptions\AiProviderException;
use App\Modules\Ai\Exceptions\AiRateLimitException;
use App\Modules\Ai\Exceptions\AiResponseInvalidException;
use App\Modules\Ai\Exceptions\AiTimeoutException;
use App\Modules\Ai\Exceptions\AnalystNotReadyException;
use App\Modules\Ai\Exceptions\BudgetExceededException;
use App\Modules\Ai\Exceptions\ManualRequestThrottledException;
use App\Modules\Ai\Exceptions\OrdersSyncBehindException;
use App\Modules\Ai\Exceptions\StaleDataException;
use App\Modules\Ai\Exceptions\UnpricedModelException;
use Throwable;

/**
 * One plain sentence per failure, naming what to fix. It only ever uses the Ai module's own exception messages (built from names, statuses
 * and counts) — an unknown exception shows its CLASS only, because a database or framework message can carry SQL, bound values or paths.
 */
final class AiErrorExplainer
{
    public static function explain(Throwable $e): string
    {
        return match (true) {
            $e instanceof AnalystNotReadyException => "Not ready ({$e->reason}): {$e->getMessage()}",
            $e instanceof StaleDataException => $e->getMessage().' Refresh the data first (sync + nightly chain), or raise AI_MAX_DATA_AGE_DAYS knowing the answer will say the data is stale.',
            $e instanceof OrdersSyncBehindException => $e->getMessage().' Run the orders sync (hm:sync --entity=orders) and the nightly chain first, or raise AI_MAX_ORDERS_SYNC_BEHIND_HOURS knowing the answer will say the day is incomplete.',
            $e instanceof ManualRequestThrottledException => $e->getMessage(),
            $e instanceof BudgetExceededException => 'Refused by the monthly budget: '.$e->getMessage(),
            $e instanceof UnpricedModelException => 'Refused: '.$e->getMessage(),
            $e instanceof AiAuthenticationException => 'The provider rejected the API key (HTTP '.($e->status ?? '?').'). Check the key for the chosen AI_PROVIDER.',
            $e instanceof AiConfigurationException => 'Provider is not configured: '.$e->getMessage(),
            $e instanceof AiRateLimitException => 'The provider rate-limited the request; try again in a few minutes.',
            $e instanceof AiTimeoutException => 'Could not reach the provider (network or timeout). Run hm:ai-ping to check connectivity.',
            $e instanceof AiInvalidResponseException => 'The provider answered, but the response was unusable ('.$e->getMessage().').',
            $e instanceof AiResponseInvalidException => 'The model answered twice but neither answer passed validation ('.count($e->errors).' problems). Nothing was stored.',
            $e instanceof AiProviderException => 'Provider error: '.$e->getMessage(),
            $e instanceof ContextPiiException, $e instanceof ContextTooLargeException => 'The data context could not be built safely: '.$e->getMessage(),
            default => 'Failed unexpectedly ('.$e::class.'). See the log; no details are printed here on purpose.',
        };
    }
}
