<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Customers\Enums\AddressType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $customer_id
 * @property AddressType $type
 * @property string|null $province
 * @property string|null $city
 * @property string|null $address
 * @property string|null $postcode
 * @property bool $is_default
 */
class CustomerAddress extends Model
{
    protected $fillable = ['customer_id', 'type', 'province', 'city', 'address', 'postcode', 'is_default'];

    protected function casts(): array
    {
        return [
            'type' => AddressType::class,
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
