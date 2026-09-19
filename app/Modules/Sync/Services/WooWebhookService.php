<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\WebhookOutcome;
use App\Modules\Sync\Enums\WooWebhookTopic;
use App\Modules\Sync\Jobs\SyncEntityJob;
use App\Modules\Sync\Models\WooWebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What an AUTHENTIC Woo webhook delivery becomes (P2-09; authenticity is WooWebhookVerifier's). Only two headers are
 * needed — the topic and the delivery id; the body is never read: the queued run re-reads Woo through the API.
 *
 *  - a topic that is not routed (see WooWebhookTopic) is ignored: nothing recorded, nothing queued;
 *  - a delivery without a usable id cannot be deduplicated, so it is ignored too, with a warning (topic only);
 *  - otherwise the delivery id is recorded (UNIQUE, insert-or-ignore) and ONE incremental orders SyncEntityJob is queued
 *    on `critical` — in one transaction, so a queue failure rolls the record back (500, and Woo's retry is not swallowed
 *    as a duplicate). A repeated delivery id is a duplicate: nothing queued.
 *
 * Dedupe stops exact replays only — Woo gives each delivery attempt a new id; bursts are coalesced by the job's own
 * per-entity uniqueness. created/updated/deleted all start the same incremental run: an incremental run does not mirror
 * a deletion (Woo lists no deleted orders), and polling stays the source of correctness.
 */
final class WooWebhookService
{
    private const QUEUE = 'critical';

    /** Woo sends a 32-character hash; integers and UUIDs fit too. Anything else is not an id we can key on. */
    private const DELIVERY_ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/D';

    public function receive(?string $topicHeader, ?string $deliveryIdHeader): WebhookOutcome
    {
        $topic = WooWebhookTopic::fromHeader($topicHeader);

        if ($topic === null) {
            return WebhookOutcome::Ignored;
        }

        $deliveryId = (string) $deliveryIdHeader;

        if (preg_match(self::DELIVERY_ID_PATTERN, $deliveryId) !== 1) {
            Log::warning('Woo webhook delivery has no usable delivery id; ignored', ['topic' => $topic->value]);

            return WebhookOutcome::Ignored;
        }

        return DB::transaction(function () use ($topic, $deliveryId): WebhookOutcome {
            $recorded = WooWebhookDelivery::query()->insertOrIgnore([[
                'topic' => $topic->value,
                'woo_delivery_id' => $deliveryId,
                'received_at' => CarbonImmutable::now('UTC'),
            ]]);

            if ($recorded === 0) {
                return WebhookOutcome::Duplicate;
            }

            $this->queueOrdersRun();

            return WebhookOutcome::Accepted;
        });
    }

    /**
     * Dispatching takes the job's uniqueness lock first; if the push then fails, that lock would outlive the failure and
     * swallow Woo's retry for `uniqueFor` seconds, so it is released before the failure propagates.
     */
    private function queueOrdersRun(): void
    {
        $job = new SyncEntityJob(SyncEntity::Orders);
        $job->onQueue(self::QUEUE);

        try {
            dispatch($job);
        } catch (Throwable $e) {
            (new UniqueLock(Container::getInstance()->make(Cache::class)))->release($job);

            throw $e;
        }
    }
}
