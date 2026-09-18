<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Models\User;
use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Enums\PermissionEffect;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $permission_id
 * @property PermissionEffect $effect
 * @property int|null $created_by
 */
class PermissionOverride extends Model
{
    use Auditable;

    public $timestamps = false;

    protected $fillable = ['user_id', 'permission_id', 'effect', 'created_by'];

    protected function casts(): array
    {
        return [
            'effect' => PermissionEffect::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Permission, $this> */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
