<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * product_id / variation_id are plain ids: Orders must not load Catalog
 * models (module boundary). Resolving them is the sync sprint's job, through
 * the Catalog public service.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $woo_item_id
 * @property int|null $product_id
 * @property int|null $variation_id
 * @property string|null $sku
 * @property string $name_snapshot
 * @property int $qty
 * @property int $unit_price
 * @property int $line_subtotal
 * @property int $line_total
 * @property int $refunded_qty
 * @property int $refunded_amount
 */
class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'woo_item_id', 'product_id', 'variation_id', 'sku', 'name_snapshot',
        'qty', 'unit_price', 'line_subtotal', 'line_total', 'refunded_qty', 'refunded_amount',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price' => 'integer',
            'line_subtotal' => 'integer',
            'line_total' => 'integer',
            'refunded_qty' => 'integer',
            'refunded_amount' => 'integer',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
