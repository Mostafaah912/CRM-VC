<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use Carbon\CarbonImmutable;

/** One Woo refund as Orders' public service accepts it (Orders cannot take Sync's DTOs). Amount is positive int Toman. */
final readonly class RefundInput
{
    /**
     * @param  list<RefundItemInput>  $items
     */
    public function __construct(
        public int $wooRefundId,
        public int $amount,
        public ?string $reason,
        public CarbonImmutable $refundedAt,
        public array $items,
    ) {}
}
