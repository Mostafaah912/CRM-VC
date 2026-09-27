<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Analytics\Exceptions\DrillExportForbiddenException;
use App\Modules\Analytics\Exceptions\UnknownDrillWidgetException;
use App\Modules\Analytics\Services\DrillService;
use App\Modules\Analytics\Support\DashboardPeriod;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
| P6-07's audited CSV export — PRD Sec.21/T1: bulk PII release is always audited. Mirrors
| SegmentServiceExportTest exactly: customers.export gates the whole operation, customers.view_full_phone
| gates whether phone comes back full or masked, every permitted export is checked against a real
| AuditLog row.
*/

function grantDrillExportPermission(User $user, string ...$actions): void
{
    $role = Role::query()->create(['name' => 'drill-export-'.uniqid(), 'label' => 'exporter']);

    foreach ($actions as $action) {
        $permission = Permission::query()->firstOrCreate(['module' => 'customers', 'action' => $action], ['label' => "customers.{$action}"]);
        $role->permissions()->attach($permission);
    }

    $user->roles()->attach($role);
}

function drillStreamedCsv(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

it('throws when the user lacks customers.export, writing no audit row', function () {
    $user = User::factory()->create();
    $period = DashboardPeriod::lastDays(30, CarbonImmutable::now());
    $before = AuditLog::query()->count();

    expect(fn () => app(DrillService::class)->export('orders', $period, [], $user))
        ->toThrow(DrillExportForbiddenException::class);
    expect(AuditLog::query()->count())->toBe($before);
});

it('records an audit row naming the widget and period for a permitted export', function () {
    $user = User::factory()->create();
    grantDrillExportPermission($user, 'export');
    $period = DashboardPeriod::fromDates(CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-02'));

    drillStreamedCsv(app(DrillService::class)->export('orders', $period, [], $user));

    $log = AuditLog::query()->where('action', 'dashboard.drill_exported')->latest('id')->firstOrFail();
    expect($log->user_id)->toBe($user->id)
        ->and($log->after)->toMatchArray(['widget' => 'orders', 'from' => '2026-06-01', 'to' => '2026-06-02']);
});

it('masks the phone for an exporter without customers.view_full_phone', function () {
    $user = User::factory()->create();
    grantDrillExportPermission($user, 'export');
    $customer = Customer::factory()->create(['phone_normalized' => '989123456789']);
    Order::factory()->for($customer)->create(['is_realized' => true, 'is_fully_refunded' => false, 'ordered_at' => '2026-06-01 10:00:00', 'total' => 100_000]);
    $period = DashboardPeriod::fromDates(CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-02'));

    $csv = drillStreamedCsv(app(DrillService::class)->export('orders', $period, [], $user));

    expect($csv)->toContain('6789')->not->toContain('989123456789');
});

it('includes the full phone for an exporter with customers.view_full_phone', function () {
    $user = User::factory()->create();
    grantDrillExportPermission($user, 'export', 'view_full_phone');
    $customer = Customer::factory()->create(['phone_normalized' => '989123456789']);
    Order::factory()->for($customer)->create(['is_realized' => true, 'is_fully_refunded' => false, 'ordered_at' => '2026-06-01 10:00:00', 'total' => 100_000]);
    $period = DashboardPeriod::fromDates(CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-02'));

    $csv = drillStreamedCsv(app(DrillService::class)->export('orders', $period, [], $user));

    expect($csv)->toContain('989123456789');
});

it('writes a UTF-8 BOM and a header row', function () {
    $user = User::factory()->create();
    grantDrillExportPermission($user, 'export');
    $period = DashboardPeriod::lastDays(30, CarbonImmutable::now());

    $csv = drillStreamedCsv(app(DrillService::class)->export('orders', $period, [], $user));

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")->and($csv)->toContain('customer_id,phone,display_name');
});

it('throws for an unknown widget rather than exporting nothing silently', function () {
    $user = User::factory()->create();
    grantDrillExportPermission($user, 'export');
    $period = DashboardPeriod::lastDays(30, CarbonImmutable::now());

    expect(fn () => app(DrillService::class)->export('not-a-widget', $period, [], $user))
        ->toThrow(UnknownDrillWidgetException::class);
});

// ================================================================= cohort_period / affinity_pair export (P6-08)

it('streams a CSV of the customers behind a cohort/period cell', function () {
    $user = User::factory()->create();
    grantDrillExportPermission($user, 'export');
    $orderedAt = '2026-06-01 10:00:00';
    $cohortMonth = DB::selectOne('SELECT to_jalali_month(?::timestamptz) AS m', [$orderedAt])->m;
    $customer = Customer::factory()->create(['display_name' => 'Cohort Member']);
    DB::table('customer_metrics')->insert(['customer_id' => $customer->id, 'cohort_month' => $cohortMonth]);
    Order::factory()->for($customer)->create(['is_realized' => true, 'is_fully_refunded' => false, 'ordered_at' => $orderedAt, 'total' => 100_000]);
    $period = DashboardPeriod::lastDays(30, CarbonImmutable::now());

    $csv = drillStreamedCsv(app(DrillService::class)->export('cohort_period', $period, ['cohort_month' => $cohortMonth, 'period_number' => '0'], $user));

    expect($csv)->toContain('Cohort Member');
});

it('streams a CSV of the customers behind a product-level affinity pair', function () {
    $user = User::factory()->create();
    grantDrillExportPermission($user, 'export');
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    $customer = Customer::factory()->create(['display_name' => 'Pair Buyer']);
    DB::table('customer_product_purchases')->insert([
        ['customer_id' => $customer->id, 'product_id' => $a->id, 'orders_count' => 1, 'items_count' => 1, 'revenue' => 1],
        ['customer_id' => $customer->id, 'product_id' => $b->id, 'orders_count' => 1, 'items_count' => 1, 'revenue' => 1],
    ]);
    $period = DashboardPeriod::lastDays(30, CarbonImmutable::now());

    $csv = drillStreamedCsv(app(DrillService::class)->export('affinity_pair', $period, ['affinity_level' => 'product', 'entity_a_id' => (string) $a->id, 'entity_b_id' => (string) $b->id], $user));

    expect($csv)->toContain('Pair Buyer');
});
