<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ProductStatus;
use Database\Factories\ProductVariationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property int|null $woo_variation_id
 * @property string|null $sku
 * @property array<string, mixed> $attributes
 * @property int|null $price integer Toman
 * @property ProductStatus $status
 * @property Carbon $synced_at
 */
class ProductVariation extends Model
{
    /** @use HasFactory<ProductVariationFactory> */
    use HasFactory;

    protected $fillable = ['product_id', 'woo_variation_id', 'sku', 'attributes', 'price', 'status', 'synced_at'];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'price' => 'integer',
            'status' => ProductStatus::class,
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<ProductCost, $this> */
    public function costs(): HasMany
    {
        return $this->hasMany(ProductCost::class, 'variation_id');
    }

    protected static function newFactory(): ProductVariationFactory
    {
        return ProductVariationFactory::new();
    }
}
