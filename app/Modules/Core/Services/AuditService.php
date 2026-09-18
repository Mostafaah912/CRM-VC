<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Core\Enums\AuditActorType;
use App\Modules\Core\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Request;

/**
 * The only way audit_logs is ever written to (CLAUDE.md §7: "منطق ثبت Audit
 * نباید داخل Controllerها پخش شود"). Every `before`/`after` payload is
 * redacted before it touches the database — CLAUDE.md §6: never log
 * passwords, API keys, or full phone numbers.
 */
final class AuditService
{
    /**
     * Case-insensitive substrings of attribute keys that are never stored,
     * even inside a before/after diff: passwords, 2FA secrets, WooCommerce/AI
     * API credentials, remember tokens.
     */
    private const REDACTED_KEY_SUBSTRINGS = [
        'password',
        'remember_token',
        'secret',
        'recovery_code',
        'consumer_key',
        'consumer_secret',
        'api_key',
        'token',
        'config', // integrations.config holds encrypted Woo/AI credentials
    ];

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        AuditActorType $actorType,
        string $action,
        string $auditableType,
        int|string $auditableId,
        ?array $before = null,
        ?array $after = null,
        ?User $user = null,
        ?string $source = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'user_id' => $user?->id,
            'actor_type' => $actorType,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'before' => $before === null ? null : $this->redact($before),
            'after' => $after === null ? null : $this->redact($after),
            'ip' => Request::ip(),
            'source' => $source,
        ]);
    }

    /** @return LengthAwarePaginator<int, AuditLog> */
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        return AuditLog::query()
            ->with('user:id,name,email')
            ->latest('created_at')
            ->paginate($perPage);
    }

    /** @param  array<string, mixed>  $attributes
     * @return array<string, mixed> */
    private function redact(array $attributes): array
    {
        $redacted = [];

        foreach ($attributes as $key => $value) {
            $redacted[$key] = $this->isSensitiveKey((string) $key) ? '[REDACTED]' : $value;
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::REDACTED_KEY_SUBSTRINGS as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
