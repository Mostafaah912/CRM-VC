<?php

declare(strict_types=1);

namespace App\Modules\Sync\Models;

use App\Modules\Sync\Enums\WooWebhookTopic;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One accepted Woo webhook delivery (P2-09). woo_delivery_id is UNIQUE: a second delivery with the same id is a
 * duplicate. Written only by WooWebhookService.
 *
 * @property int $id
 * @property WooWebhookTopic $topic
 * @property string $woo_delivery_id
 * @property CarbonImmutable $received_at
 */
class WooWebhookDelivery extends Model
{
    public $timestamps = false;

    protected $fillable = ['topic', 'woo_delivery_id', 'received_at'];

    protected function casts(): array
    {
        return [
            'topic' => WooWebhookTopic::class,
            'received_at' => 'immutable_datetime',
        ];
    }
}
