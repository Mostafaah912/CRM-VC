<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use App\Modules\Customers\Models\Customer;
use App\Modules\Segments\Exceptions\SegmentException;
use App\Modules\Segments\Models\Segment;
use App\Modules\Segments\Services\SegmentService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
| PRD §20/§21 (T1: bulk PII release is always audited). customers.export gates the whole operation;
| customers.view_full_phone gates whether phone comes back full or masked (same mask as the
| customer list, P3-01). Every export — permitted or refused — is checked against a real AuditLog
| row where relevant. CSV content is read via ob_start()/sendContent() on the real StreamedResponse,
| not a mock.
*/

function grantSegmentExportPermission(User $user, string ...$actions): void
{
    $role = Role::query()->create(['name' => 'export-role-'.uniqid(), 'label' => 'exporter']);

    foreach ($actions as $action) {
        $permission = Permission::query()->firstOrCreate(
            ['module' => 'customers', 'action' => $action],
            ['label' => "customers.{$action}"],
        );
        $role->permissions()->attach($permission);
    }

    $user->roles()->attach($role);
}

function streamedCsv(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

/** @param array<string, mixed> $customerOverrides */
function segmentWithOneMember(array $customerOverrides = []): Segment
{
    $customer = Customer::factory()->create($customerOverrides);
    DB::table('customer_metrics')->insert(['customer_id' => $customer->id, 'total_orders' => 2, 'total_revenue' => 500000, 'rfm_segment' => 'loyal']);

    $segment = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);
    app(SegmentService::class)->evaluate($segment);

    return $segment->fresh();
}

it('throws SegmentException and writes no audit row when the user lacks customers.export', function () {
    $user = User::factory()->create();
    $segment = segmentWithOneMember();

    $before = AuditLog::query()->count();

    try {
        app(SegmentService::class)->export($segment, $user);
        expect(false)->toBeTrue('Expected SegmentException, none thrown.');
    } catch (SegmentException $e) {
        expect($e->reason)->toBe(SegmentException::EXPORT_FORBIDDEN)
            ->and($e->getMessage())->toMatch('/\p{Arabic}/u');
    }

    expect(AuditLog::query()->count())->toBe($before);
});

it('records an audit row for a permitted export', function () {
    $user = User::factory()->create();
    grantSegmentExportPermission($user, 'export');
    $segment = segmentWithOneMember();

    $response = app(SegmentService::class)->export($segment, $user);
    streamedCsv($response);

    $log = AuditLog::query()->where('action', 'segment.exported')->latest('id')->firstOrFail();

    expect($log->user_id)->toBe($user->id)
        ->and($log->auditable_type)->toBe(Segment::class)
        ->and($log->auditable_id)->toBe($segment->id);
});

it('masks phone numbers for an exporter without customers.view_full_phone', function () {
    $user = User::factory()->create();
    grantSegmentExportPermission($user, 'export');
    $segment = segmentWithOneMember(['phone_normalized' => '989123456789']);

    $csv = streamedCsv(app(SegmentService::class)->export($segment, $user));

    expect($csv)->toContain('6789');
    expect($csv)->not->toContain('989123456789');
});

it('includes the full phone number for an exporter with customers.view_full_phone', function () {
    $user = User::factory()->create();
    grantSegmentExportPermission($user, 'export', 'view_full_phone');
    $segment = segmentWithOneMember(['phone_normalized' => '989123456789']);

    $csv = streamedCsv(app(SegmentService::class)->export($segment, $user));

    expect($csv)->toContain('989123456789');
});

it('writes a UTF-8 BOM and a header row', function () {
    $user = User::factory()->create();
    grantSegmentExportPermission($user, 'export');
    $segment = segmentWithOneMember();

    $csv = streamedCsv(app(SegmentService::class)->export($segment, $user));

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($csv)->toContain('customer_id,phone,display_name');
});
