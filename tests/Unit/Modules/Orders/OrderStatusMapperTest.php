<?php

declare(strict_types=1);

use App\Modules\Orders\Services\OrderStatusMapper;
use Tests\Arch\Scanner;
use Tests\TestCase;

/*
| TEST FIRST (PRD §24, CLAUDE.md §8). `is_realized` is derived from config('woo.realized_statuses')
| and nothing else. Order status is a raw, store-defined Woo slug (ARCHITECTURE.md P1-03): there is no
| status Enum and no app-side list, so a custom status must be classifiable by changing config alone.
| Refund AMOUNTS are not this mapper's input: a partial refund leaves Woo's status alone, a full refund
| through Woo's status flow sets `refunded` — both are decided by the status string, and refund
| bookkeeping (is_fully_refunded, refunded_total) is P2-07.
|
| Runs with the app booted (config) but no database.
*/
uses(TestCase::class);

/** Datasets are built before the app boots, so read the same config file straight from disk. */
function wooConfigStatuses(string $key): array
{
    return array_map(fn (string $s) => [$s], (require dirname(__DIR__, 4).'/config/woo.php')[$key]);
}

function orderStatusMapper(): OrderStatusMapper
{
    return new OrderStatusMapper;
}

it('treats exactly the configured realized statuses as realized', function (string $status) {
    expect(orderStatusMapper()->isRealized($status))->toBeTrue();
})->with(fn () => wooConfigStatuses('realized_statuses'));

it('treats every configured excluded status as not realized', function (string $status) {
    expect(orderStatusMapper()->isRealized($status))->toBeFalse();
})->with(fn () => wooConfigStatuses('excluded_statuses'));

it('covers every status Woo has: realized ones are true, all others false', function (string $status, bool $realized) {
    expect(orderStatusMapper()->isRealized($status))->toBe($realized);
})->with([
    'processing' => ['processing', true],
    'completed' => ['completed', true],
    'pending' => ['pending', false],
    'on-hold' => ['on-hold', false],
    'cancelled' => ['cancelled', false],
    'failed' => ['failed', false],
    'trash' => ['trash', false],
    'refunded' => ['refunded', false],
    'checkout-draft' => ['checkout-draft', false],
]);

it('keeps a partially refunded order realized: Woo leaves its status at completed', function () {
    expect(orderStatusMapper()->isRealized('completed'))->toBeTrue();
});

it('does not count an order Woo moved to the refunded status (full refund) as realized', function () {
    expect(orderStatusMapper()->isRealized('refunded'))->toBeFalse();
});

it('never turns an unknown status into a realized one', function (string $status) {
    expect(orderStatusMapper()->isRealized($status))->toBeFalse();
})->with([
    'unknown slug' => ['some-plugin-status'],
    'empty' => [''],
    'wc prefix is a different string' => ['wc-completed'],
    'case matters' => ['Completed'],
    'padded' => [' completed'],
    'numeric string' => ['0'],
]);

it('reads the realized list from config, not from code: changing config changes the answer', function () {
    config(['woo.realized_statuses' => ['shipped']]);

    expect(orderStatusMapper()->isRealized('shipped'))->toBeTrue()
        ->and(orderStatusMapper()->isRealized('completed'))->toBeFalse()
        ->and(orderStatusMapper()->isRealized('processing'))->toBeFalse();
});

it('re-reads config on every call, so a runtime change is honoured', function () {
    $mapper = orderStatusMapper();
    expect($mapper->isRealized('completed'))->toBeTrue();

    config(['woo.realized_statuses' => ['processing']]);

    expect($mapper->isRealized('completed'))->toBeFalse()
        ->and($mapper->isRealized('processing'))->toBeTrue();
});

it('realizes nothing, rather than everything, when the configured list is empty', function () {
    config(['woo.realized_statuses' => []]);

    expect(orderStatusMapper()->isRealized('completed'))->toBeFalse();
});

it('refuses a broken configuration instead of guessing', function (mixed $broken) {
    config(['woo.realized_statuses' => $broken]);

    orderStatusMapper()->isRealized('completed');
})->with([
    'null' => [null],
    'a string' => ['completed'],
    'non-string entry' => [['completed', 5]],
    'blank entry' => [['completed', '']],
])->throws(LogicException::class, 'woo.realized_statuses');

it('contains no status literal and gets the list only from config', function () {
    $file = Scanner::root().'/app/Modules/Orders/Services/OrderStatusMapper.php';

    expect(is_file($file))->toBeTrue()
        ->and(Scanner::violations([$file], ["/['\"](wc-)?(processing|completed|shipped|on-hold|cancelled|refunded|pending|failed|trash)['\"]/"]))->toBe([])
        ->and((string) file_get_contents($file))->toContain("config('woo.realized_statuses')");
});
