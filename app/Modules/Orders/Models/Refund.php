<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id
 * @property int $woo_refund_id
 * @property int $amount integer Toman
 * @property bool $is_full
 * @property string|null $reason
 * @property Carbon $refunded_at
 */
class Refund extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'woo_refund_id', 'amount', 'is_full', 'reason', 'refunded_at'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'is_full' => 'boolean',
            'refunded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
