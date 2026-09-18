<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\OrderHistorySource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id
 * @property string|null $from_status
 * @property string $to_status
 * @property Carbon $changed_at
 * @property OrderHistorySource $source
 */
class OrderStatusHistory extends Model
{
    protected $table = 'order_status_history';

    public $timestamps = false;

    protected $fillable = ['order_id', 'from_status', 'to_status', 'changed_at', 'source'];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
            'source' => OrderHistorySource::class,
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
