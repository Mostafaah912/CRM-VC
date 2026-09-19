<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use App\Modules\Sync\Enums\ReconciliationStatus;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncMode;
use App\Modules\Sync\Enums\SyncStatus;
use App\Modules\Sync\Models\ReconciliationReportModel;
use App\Modules\Sync\Models\SyncJob;
use App\Modules\Sync\Support\ReconciliationMonths;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Users with exactly the given permissions, and the rows the P2-12 system pages read. Test data only. */
final class SystemPageFixtures
{
    /** A user whose only role grants exactly these "module.action" keys. */
    public static function userWith(string ...$keys): User
    {
        $role = Role::query()->create(['name' => 'role-'.Str::lower(Str::random(10)), 'label' => 'Test role']);

        foreach ($keys as $key) {
            [$module, $action] = explode('.', $key, 2);
            $permission = Permission::query()->firstOrCreate(['module' => $module, 'action' => $action], ['label' => $key]);
            $role->permissions()->attach($permission);
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    public static function run(SyncStatus $status, CarbonImmutable $startedAt, array $overrides = []): SyncJob
    {
        return SyncJob::query()->create([
            'entity' => SyncEntity::Orders,
            'mode' => SyncMode::Incremental,
            'status' => $status,
            'cursor_from' => $startedAt->subMinutes(15),
            'cursor_to' => $startedAt,
            'pages_processed' => 2,
            'records_processed' => 3,
            'records_failed' => 0,
            'started_at' => $startedAt,
            'finished_at' => $status === SyncStatus::Running ? null : $startedAt->addSeconds(42),
            'error' => null,
            ...$overrides,
        ]);
    }

    public static function report(string $month, ReconciliationStatus $status, int $countDiff = 0, string $percent = '0.0000', ?string $error = null): ReconciliationReportModel
    {
        [$first, $last] = (new ReconciliationMonths)->dates($month);
        $measured = $status !== ReconciliationStatus::Failed;

        return ReconciliationReportModel::query()->create([
            'jalali_month' => $month,
            'period_start' => $first,
            'period_end' => $last,
            'woo_orders' => $measured ? 100 : null,
            'crm_orders' => $measured ? 100 - $countDiff : null,
            'woo_revenue' => $measured ? 1_000_000 : null,
            'crm_revenue' => $measured ? 1_000_000 : null,
            'orders_diff' => $measured ? $countDiff : null,
            'revenue_diff' => $measured ? 0 : null,
            'diff_percent' => $measured ? $percent : null,
            'is_acceptable' => $status === ReconciliationStatus::Green,
            'details' => $measured ? ['window' => ['start' => 'x', 'end' => 'y'], 'realized_statuses' => ['completed']] : null,
            'status' => $status,
            'error_message' => $error,
            'reconciled_at' => $measured ? CarbonImmutable::now('UTC') : null,
        ]);
    }

    /**
     * The props this page itself sends: Inertia's shared props (auth user, flash, errors) are not the page's business.
     *
     * @param  array<array-key, mixed>  $props
     * @return array<array-key, mixed>
     */
    public static function pageProps(array $props): array
    {
        return array_diff_key($props, array_flip(['auth', 'name', 'sidebarOpen', 'errors', 'flash']));
    }

    /**
     * Every key at any depth of a props array, dotted-free: used to prove a forbidden key appears nowhere in a response.
     *
     * @param  array<array-key, mixed>  $props
     * @return list<string>
     */
    public static function keysDeep(array $props): array
    {
        $keys = [];

        foreach ($props as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($value)) {
                array_push($keys, ...self::keysDeep($value));
            }
        }

        return $keys;
    }

    /** Keys that must never reach any system page: cursors, credentials, internal ids and raw payloads. */
    public const FORBIDDEN_KEYS = [
        'id', 'sync_job_id', 'job_id', 'uuid', 'cursor_from', 'cursor_to', 'cursor', 'cursor_value',
        'key', 'secret', 'consumer_key', 'consumer_secret', 'webhook_secret', 'password', 'token', 'api_key', 'config',
        'payload', 'exception', 'trace', 'context', 'details',
        'customer_id', 'existing_name', 'incoming_name', 'phone', 'phone_normalized',
    ];
}
