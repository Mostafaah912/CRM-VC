<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Customers\Enums\CustomerEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One entry of a customer's timeline (P3-04). The timeline is READ by CustomerTimelineService, which passes only an allowlist of
 * `payload` keys on; nothing in this build writes here yet.
 *
 * @property int $id
 * @property int $customer_id
 * @property CustomerEventType $event_type
 * @property array<array-key, mixed>|null $payload
 * @property Carbon $happened_at
 * @property Carbon $created_at
 */
class CustomerEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['customer_id', 'event_type', 'payload', 'happened_at'];

    protected function casts(): array
    {
        return [
            'event_type' => CustomerEventType::class,
            'payload' => 'array',
            'happened_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
