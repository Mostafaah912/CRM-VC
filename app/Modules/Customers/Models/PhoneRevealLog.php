<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One reveal of a customer's full phone (P3-02). Written ONLY by PhoneRevealService, in the transaction that releases the number;
 * never updated, never deleted by the application. Holds no phone and no name — only who, which customer, when and from where.
 *
 * @property int $id
 * @property int $customer_id
 * @property int $revealed_by
 * @property Carbon $revealed_at
 * @property string|null $ip
 * @property string $user_agent
 */
class PhoneRevealLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['customer_id', 'revealed_by', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['revealed_at' => 'datetime'];
    }
}
