<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\CostSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $variation_id
 * @property int $unit_cost integer Toman
 * @property Carbon $effective_from
 * @property CostSource $source
 */
class ProductCost extends Model
{
    public $timestamps = false;

    protected $fillable = ['variation_id', 'unit_cost', 'effective_from', 'source'];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'integer',
            'effective_from' => 'date',
            'source' => CostSource::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductVariation, $this> */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }
}
