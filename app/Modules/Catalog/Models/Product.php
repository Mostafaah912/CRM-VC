<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\ProductType;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A mirror of a Woo product — Woo stays the source of truth.
 *
 * @property int $id
 * @property int $woo_product_id
 * @property string $name
 * @property string|null $slug
 * @property ProductType $type
 * @property ProductStatus $status
 * @property Carbon|null $created_at_woo
 * @property Carbon $synced_at
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = ['woo_product_id', 'name', 'slug', 'type', 'status', 'created_at_woo', 'synced_at'];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'status' => ProductStatus::class,
            'created_at_woo' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<ProductCategory, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ProductCategory::class, 'product_category_product', 'product_id', 'category_id');
    }

    /** @return HasMany<ProductVariation, $this> */
    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
