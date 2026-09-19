<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Models\User;
use App\Modules\Customers\Enums\IdentityConflictStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property string|null $existing_name
 * @property string|null $incoming_name
 * @property int|null $woo_order_id
 * @property string $reason
 * @property IdentityConflictStatus $status
 * @property int|null $resolved_by
 * @property Carbon $created_at
 */
class IdentityConflict extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id', 'existing_name', 'incoming_name', 'woo_order_id',
        'reason', 'status', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => IdentityConflictStatus::class,
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
