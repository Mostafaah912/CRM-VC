<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Sync\Enums\ReconciliationStatus;
use App\Modules\Sync\Jobs\ReconcileMonthJob;
use App\Modules\Sync\Models\ReconciliationReportModel;
use App\Modules\Sync\Services\WooClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Input\StringInput;
use Tests\Support\WooOrderTotalsSimulator;

/*
| P2-11 — `hm:reconcile {--month=} {--all}`. --month=YYYY-MM reconciles that month now and prints one line; --all queues a
| ReconcileMonthJob per complete month (from the first, Mehr 1403, to the last complete one); with neither, the last
| complete month is reconciled now. Both flags together, and a month that is invalid, before the first or not complete, exit 1
| and touch nothing. A red month is a RESULT, not a usage error: exit 0. It prints counts and a percentage — nothing else.
| Scheduled daily at 02:00 Asia/Tehran (--all).
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
    $this->travelTo(CarbonImmutable::parse('2025-01-10 12:00:00', 'UTC')); // Tehran 1403-10-21: last complete month 1403-09
});

/** @return array{int, string} */
function reconCommand(array $options = []): array
{
    $code = Artisan::call('hm:reconcile', $options);

    return [$code, Artisan::output()];
}

function commandWoo(?WooOrderTotalsSimulator $woo = null): WooOrderTotalsSimulator
{
    $woo ??= new WooOrderTotalsSimulator([]);
    app()->instance(WooClient::class, $woo);

    return $woo;
}

it('reconciles the last complete month when given no flag, and prints one line', function () {
    commandWoo();

    [$code, $output] = reconCommand();

    expect($code)->toBe(0)->and($output)->toBe("1403-09: green (orders 0/0, revenue diff 0.0000%)\n");
    expect(ReconciliationReportModel::sole()->jalali_month)->toBe('1403-09');
});

it('reconciles the given month now', function () {
    commandWoo();

    [$code, $output] = reconCommand(['--month' => '1403-07']);

    expect($code)->toBe(0)->and($output)->toBe("1403-07: green (orders 0/0, revenue diff 0.0000%)\n");
    expect(ReconciliationReportModel::sole()->jalali_month)->toBe('1403-07');
});

it('prints a red month as a result, with the counts and the percentage, and exits 0', function () {
    $customer = Customer::factory()->create();
    Order::factory()->create(['customer_id' => $customer->id, 'status' => 'processing', 'is_realized' => true, 'total' => 1_000_000, 'ordered_at' => CarbonImmutable::parse('2024-10-01 08:00:00', 'UTC')]);
    commandWoo(new WooOrderTotalsSimulator([['created' => '2024-10-01 08:00:00', 'status' => 'processing', 'total' => 900_000]]));

    [$code, $output] = reconCommand(['--month' => '1403-07']);

    expect($code)->toBe(0)->and($output)->toBe("1403-07: red (orders 1/1, revenue diff 11.1111%)\n");
    expect(ReconciliationReportModel::sole()->status)->toBe(ReconciliationStatus::Red);
});

it('refuses a month that is invalid, before the first, or not complete: exit 1, a static message, nothing asked or written', function (string $month) {
    $woo = commandWoo();

    [$code, $output] = reconCommand(['--month' => $month]);

    expect($code)->toBe(1)->and($output)->not->toContain('1403-09:');
    if ($month !== '') {
        expect($output)->not->toContain($month);
    }
    expect($woo->requests)->toBe([])->and(ReconciliationReportModel::count())->toBe(0);
})->with([
    'the current month' => ['1403-10'],
    'a future month' => ['1404-05'],
    'before the first month' => ['1403-06'],
    'a bad format' => ['1403-7'],
    'a Gregorian-looking date' => ['2024-10'],
    'an empty value' => [''],
]);

it('refuses --month together with --all: exit 1, nothing done', function () {
    Queue::fake();
    $woo = commandWoo();

    [$code, $output] = reconCommand(['--month' => '1403-07', '--all' => true]);

    expect($code)->toBe(1)->and($output)->toContain('either --month or --all');
    expect($woo->requests)->toBe([])->and(ReconciliationReportModel::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('queues one job per complete month with --all and prints one line', function () {
    Queue::fake();
    commandWoo();

    [$code, $output] = reconCommand(['--all' => true]);

    expect($code)->toBe(0)->and($output)->toBe("Dispatched reconciliation for 3 months (1403-07 to 1403-09)\n");
    Queue::assertPushed(ReconcileMonthJob::class, 3);
    Queue::assertPushedOn('sync', ReconcileMonthJob::class, fn (ReconcileMonthJob $job) => $job->jalaliMonth === '1403-07');
    expect(ReconciliationReportModel::count())->toBe(0);
});

it('says so, and exits 0, when no month is complete yet', function () {
    Queue::fake();
    commandWoo();
    $this->travelTo(CarbonImmutable::parse('2024-10-01 12:00:00', 'UTC'));

    [$code, $output] = reconCommand(['--all' => true]);

    expect($code)->toBe(0)->and($output)->toBe("No complete month to reconcile yet\n");
    Queue::assertNothingPushed();
});

it('reports a failed reconciliation with a scrubbed reason and exit 1, and the month is marked failed', function () {
    commandWoo(new WooOrderTotalsSimulator([], failure: new RuntimeException('bad billing 09121234567')));

    [$code, $output] = reconCommand(['--month' => '1403-07']);

    expect($code)->toBe(1)->and($output)->toContain('RuntimeException')->not->toContain('09121234567');
    expect(ReconciliationReportModel::sole()->status)->toBe(ReconciliationStatus::Failed);
});

it('prints no secret, credential or payload: only its one line', function () {
    config(['woo.key' => 'ck_secret_marker', 'woo.secret' => 'cs_secret_marker', 'woo.webhook_secret' => 'whsec_secret_marker']);
    commandWoo();

    [, $output] = reconCommand(['--month' => '1403-08']);

    expect($output)->toBe("1403-08: green (orders 0/0, revenue diff 0.0000%)\n");
});

// ================================================================== the schedule

/** @return list<Event> */
function scheduledReconcileEvents(): array
{
    Artisan::all();

    return array_values(array_filter(app(Schedule::class)->events(), fn ($event) => str_contains((string) $event->command, 'hm:reconcile')));
}

it('schedules hm:reconcile --all daily at 02:00 in Asia/Tehran', function () {
    $events = scheduledReconcileEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('0 2 * * *')
        ->and((string) $events[0]->timezone)->toBe('Asia/Tehran')
        ->and($events[0]->command)->toContain('hm:reconcile')->toContain('--all')
        ->and($events[0]->withoutOverlapping)->toBeFalse();
});

it('schedules a command line the command actually accepts: --all is a flag, and Laravel would compile [--all => true] into --all=\'1\', which it rejects', function () {
    $command = Artisan::all()['hm:reconcile'];
    $line = (string) scheduledReconcileEvents()[0]->command;
    $arguments = trim(substr($line, strpos($line, 'hm:reconcile') + strlen('hm:reconcile')));

    $input = new StringInput($arguments);
    $input->bind($command->getDefinition()); // throws if the scheduled arguments are not valid for the command

    expect($input->getOption('all'))->toBeTrue()->and($input->getOption('month'))->toBeNull();
});

it('schedules exactly three tasks: the orders poll, the nightly catalog sync (P6 decision), and the nightly reconciliation', function () {
    Artisan::all();

    $commands = array_map(fn ($event) => (string) $event->command, app(Schedule::class)->events());

    expect($commands)->toHaveCount(3)
        ->and(implode(' ', $commands))->toContain('hm:sync')->toContain('hm:reconcile')
        ->and(implode(' ', $commands))->toContain('catalog');
});
