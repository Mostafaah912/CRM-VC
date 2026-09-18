<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\LifecycleStage;
use App\Support\PhoneNormalizer;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The one entity any module may read (CLAUDE.md §1). One phone = one customer.
 * Holds no aggregate counters — those live in customer_metrics.
 *
 * @property int $id
 * @property string $phone_normalized
 * @property string|null $phone_raw_last
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $display_name
 * @property string|null $email
 * @property string|null $province
 * @property string|null $city
 * @property CustomerStatus $status
 * @property LifecycleStage $lifecycle_stage
 * @property bool $metrics_dirty
 * @property bool $needs_review
 * @property Carbon|null $first_seen_at
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'phone_normalized', 'phone_raw_last', 'first_name', 'last_name', 'display_name',
        'email', 'province', 'city', 'status', 'lifecycle_stage',
        'metrics_dirty', 'needs_review', 'first_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
            'lifecycle_stage' => LifecycleStage::class,
            'metrics_dirty' => 'boolean',
            'needs_review' => 'boolean',
            'first_seen_at' => 'datetime',
        ];
    }

    /**
     * Every phone written through Eloquent is normalized here, so a
     * hand-typed 0912... can never become a second customer (CLAUDE.md §2).
     *
     * @return Attribute<string, string>
     */
    protected function phoneNormalized(): Attribute
    {
        return Attribute::set(fn (string $value) => PhoneNormalizer::normalize($value));
    }

    /** @return HasMany<CustomerIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(CustomerIdentity::class);
    }

    /** @return HasMany<IdentityConflict, $this> */
    public function identityConflicts(): HasMany
    {
        return $this->hasMany(IdentityConflict::class);
    }

    /** @return HasMany<CustomerAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /** @return HasMany<CustomerNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }
}
