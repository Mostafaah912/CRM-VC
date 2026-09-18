<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $module
 * @property string $action
 * @property string $label
 */
class Permission extends Model
{
    public $timestamps = false;

    protected $fillable = ['module', 'action', 'label'];

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }

    public function key(): string
    {
        return "{$this->module}.{$this->action}";
    }
}
