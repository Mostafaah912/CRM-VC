<?php

declare(strict_types=1);

namespace App\Modules\Core\Concerns;

use App\Modules\Core\Enums\AuditActorType;
use App\Modules\Core\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Drop onto any sensitive Core model (PermissionOverride, Settings, ...) to
 * automatically log create/update/delete through AuditService — so audit
 * coverage never depends on every call site remembering to log it manually.
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            self::audit($model, 'created', after: $model->getAttributes());
        });

        static::updated(function (Model $model): void {
            $changedKeys = array_keys($model->getChanges());

            self::audit(
                $model,
                'updated',
                before: array_intersect_key($model->getOriginal(), array_flip($changedKeys)),
                after: $model->getChanges(),
            );
        });

        static::deleted(function (Model $model): void {
            self::audit($model, 'deleted', before: $model->getAttributes());
        });
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private static function audit(Model $model, string $verb, ?array $before = null, ?array $after = null): void
    {
        $user = Auth::user();

        app(AuditService::class)->record(
            actorType: $user ? AuditActorType::User : AuditActorType::System,
            action: strtolower(class_basename($model)).'.'.$verb,
            auditableType: $model::class,
            auditableId: $model->getKey(),
            before: $before,
            after: $after,
            user: $user,
            source: 'model',
        );
    }
}
