<?php

declare(strict_types=1);

use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncMode;
use App\Modules\Sync\Enums\SyncStatus;
use App\Modules\Sync\Jobs\CatalogSyncJob;
use App\Modules\Sync\Jobs\SyncEntityJob;
use App\Modules\Sync\Models\SyncCursor;
use App\Modules\Sync\Models\SyncJob;
use App\Modules\Sync\Models\SyncLog;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Input\StringInput;

/*
| P2-10 — `hm:sync {--entity=orders} {--full}`: dispatches ONE SyncEntityJob (queue `sync`) and exits without waiting;
| prints exactly one line and nothing else — no cursor, no epoch, no payload, no secret. It writes nothing itself: the
| stored cursor is not touched (a full run is run-scoped). It is scheduled every 15 minutes, incremental.
*/

beforeEach(function () {
    Queue::fake();
});

/** @return array{int, string} */
function syncCommand(array $options = []): array
{
    $code = Artisan::call('hm:sync', $options);

    return [$code, Artisan::output()];
}

// ================================================================== the command

it('dispatches one incremental orders sync and prints one line', function () {
    [$code, $output] = syncCommand();

    expect($code)->toBe(0)->and($output)->toBe("Dispatched sync for orders (incremental)\n");
    Queue::assertPushed(SyncEntityJob::class, 1);
    Queue::assertPushedOn('sync', SyncEntityJob::class, fn (SyncEntityJob $job) => $job->entity === SyncEntity::Orders && $job->mode === SyncMode::Incremental);
});

it('dispatches a full sync with --full', function () {
    [$code, $output] = syncCommand(['--full' => true]);

    expect($code)->toBe(0)->and($output)->toBe("Dispatched sync for orders (full)\n");
    Queue::assertPushed(SyncEntityJob::class, 1);
    Queue::assertPushedOn('sync', SyncEntityJob::class, fn (SyncEntityJob $job) => $job->entity === SyncEntity::Orders && $job->mode === SyncMode::Full);
});

it('takes the entity from --entity', function () {
    [$code, $output] = syncCommand(['--entity' => 'orders']);

    expect($code)->toBe(0)->and($output)->toBe("Dispatched sync for orders (incremental)\n");
});

it('refuses an unknown entity with exit code 1, dispatches nothing and does not echo what was typed', function (string $entity) {
    [$code, $output] = syncCommand(['--entity' => $entity]);

    expect($code)->toBe(1)
        ->and($output)->toContain('Supported: orders')->not->toContain('Dispatched');
    if ($entity !== '') {
        expect($output)->not->toContain($entity);
    }
    Queue::assertNothingPushed();
})->with(['customers', 'products', 'all', 'ORDERS', 'orders,refunds', 'refunds', '']);

// ================================================================== --entity=catalog (P6 decision)

it('dispatches a catalog sync directly, never a SyncEntityJob, and ignores --full', function () {
    [$code, $output] = syncCommand(['--entity' => 'catalog']);

    expect($code)->toBe(0)->and($output)->toBe("Dispatched sync for catalog\n");
    Queue::assertPushed(CatalogSyncJob::class, 1);
    Queue::assertNotPushed(SyncEntityJob::class);
});

it('dispatches a catalog sync on the sync queue', function () {
    syncCommand(['--entity' => 'catalog']);

    Queue::assertPushedOn('sync', CatalogSyncJob::class);
});

it('never queues on the critical queue: that one is for webhooks', function () {
    syncCommand(['--full' => true]);
    syncCommand();

    Queue::assertNotPushed(SyncEntityJob::class, fn (SyncEntityJob $job) => $job->queue === 'critical');
});

it('dispatches and exits: it neither waits for nor starts a run, and leaves the stored cursor alone', function () {
    SyncCursor::create(['entity' => 'orders', 'cursor_value' => '2026-06-01 11:30:00+00', 'last_status' => SyncStatus::Completed]);

    syncCommand(['--full' => true]);

    $cursor = SyncCursor::findOrFail('orders');
    expect(SyncJob::count())->toBe(0)
        ->and(SyncLog::count())->toBe(0)
        ->and($cursor->cursor_value->utc()->format('Y-m-d\TH:i:s'))->toBe('2026-06-01T11:30:00')
        ->and($cursor->last_status)->toBe(SyncStatus::Completed);
});

it('prints no secret, cursor, epoch or payload — only the one line', function () {
    config(['woo.key' => 'ck_secret_marker', 'woo.secret' => 'cs_secret_marker', 'woo.webhook_secret' => 'whsec_secret_marker']);
    SyncCursor::create(['entity' => 'orders', 'cursor_value' => '2026-06-01 11:30:00+00', 'last_status' => SyncStatus::Completed]);

    [, $output] = syncCommand(['--full' => true]);

    expect($output)->toBe("Dispatched sync for orders (full)\n");
});

// ================================================================== the schedule

/** @return list<Event> */
function scheduledSyncEvents(): array
{
    Artisan::all(); // boots the console kernel, which loads routes/console.php

    return array_values(array_filter(app(Schedule::class)->events(), fn ($event) => str_contains((string) $event->command, 'hm:sync')));
}

/** @return list<Event> */
function scheduledOrdersSyncEvents(): array
{
    return array_values(array_filter(scheduledSyncEvents(), fn ($event) => preg_match("/--entity='?orders'?(\s|$)/", (string) $event->command) === 1));
}

it('schedules exactly one hm:sync for orders every 15 minutes in Asia/Tehran', function () {
    $events = scheduledOrdersSyncEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('*/15 * * * *')
        ->and((string) $events[0]->timezone)->toBe('Asia/Tehran')
        ->and($events[0]->command)->toMatch("/hm:sync --entity='?orders'?(\s|$)/");
});

it('schedules a command line the command actually accepts', function () {
    $command = Artisan::all()['hm:sync'];
    $line = (string) scheduledOrdersSyncEvents()[0]->command;
    $arguments = trim(substr($line, strpos($line, 'hm:sync') + strlen('hm:sync')));

    $input = new StringInput($arguments);
    $input->bind($command->getDefinition());

    expect($input->getOption('entity'))->toBe('orders')->and($input->getOption('full'))->toBeFalse();
});

it('schedules the incremental poll, not a full sync', function () {
    expect(scheduledOrdersSyncEvents()[0]->command)->not->toContain('--full');
});

it('leaves overlap protection to the P2-08 run rule and the job\'s uniqueness: the command exits at once', function () {
    $event = scheduledOrdersSyncEvents()[0];

    expect($event->withoutOverlapping)->toBeFalse()
        ->and($event->onOneServer)->toBeFalse();
});

// ================================================== hm:sync --entity=catalog (P6 decision)

it('schedules exactly one hm:sync for catalog, daily at 01:30 Asia/Tehran', function () {
    $events = array_values(array_filter(scheduledSyncEvents(), fn ($event) => preg_match("/--entity='?catalog'?(\s|$)/", (string) $event->command) === 1));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('30 1 * * *')
        ->and((string) $events[0]->timezone)->toBe('Asia/Tehran');
});

// P2-11 adds the nightly reconciliation; the "exactly three tasks" guard lives in ReconcileCommandTest.
