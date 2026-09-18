<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Core\Enums\AuditActorType;
use App\Modules\Core\Enums\SettingKey;
use App\Modules\Core\Events\SettingChanged;
use App\Modules\Core\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SettingService
{
    public function __construct(private readonly AuditService $audit) {}

    public function get(SettingKey $key): bool|int|float
    {
        $stored = Cache::rememberForever(
            $this->cacheKey($key),
            fn () => Setting::query()->find($key->value)?->value,
        );

        return $stored === null ? $key->default() : $key->cast($stored);
    }

    /** @throws ValidationException */
    public function set(SettingKey $key, mixed $value, ?User $actor = null): void
    {
        $validated = Validator::make(['value' => $value], ['value' => $key->rules()])->validate();

        $new = $key->cast($validated['value']);
        $old = $this->get($key);

        if ($old === $new && Setting::query()->whereKey($key->value)->exists()) {
            return;
        }

        DB::transaction(function () use ($key, $new, $actor): void {
            Setting::query()->updateOrCreate(
                ['key' => $key->value],
                ['value' => $new, 'updated_by' => $actor?->id, 'updated_at' => now()],
            );
        });

        Cache::forget($this->cacheKey($key));

        // audit_logs.auditable_id is bigint but settings.key is a string, so the
        // key travels in the before/after payload and auditable_id is 0.
        $this->audit->record(
            actorType: $actor ? AuditActorType::User : AuditActorType::System,
            action: 'setting.updated',
            auditableType: Setting::class,
            auditableId: 0,
            before: ['key' => $key->value, 'value' => $old],
            after: ['key' => $key->value, 'value' => $new],
            user: $actor,
            source: 'settings',
        );

        SettingChanged::dispatch($key, $old, $new);
    }

    private function cacheKey(SettingKey $key): string
    {
        return 'setting:'.$key->value;
    }
}
