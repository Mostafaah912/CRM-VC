<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Customers\Enums\IdentityConfidence;
use App\Modules\Customers\Enums\IdentitySource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $customer_id
 * @property IdentitySource $source
 * @property string $source_id
 * @property IdentityConfidence $confidence
 */
class CustomerIdentity extends Model
{
    public $timestamps = false;

    protected $fillable = ['customer_id', 'source', 'source_id', 'confidence'];

    protected function casts(): array
    {
        return [
            'source' => IdentitySource::class,
            'confidence' => IdentityConfidence::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
