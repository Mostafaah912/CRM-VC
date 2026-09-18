<?php

declare(strict_types=1);

use App\Modules\Sync\Support\SyncWindow;
use Carbon\CarbonImmutable;

/*
| PRD §10 "Cursor و صفحه‌بندی": cursor_to = now() is FROZEN at job start,
| cursor_from = cursor_value - overlap_minutes. The window is an immutable value:
| nothing in it can drift while a job paginates.
*/

it('freezes cursor_to at job start and derives cursor_from from the stored cursor minus the overlap', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));

    $window = SyncWindow::freeze(CarbonImmutable::parse('2026-03-01 09:30:00', 'UTC'), overlapMinutes: 10);

    expect($window->modifiedBefore->toIso8601String())->toBe('2026-03-01T10:00:00+00:00')
        ->and($window->modifiedAfter?->toIso8601String())->toBe('2026-03-01T09:20:00+00:00');
});

it('never moves after it is frozen, however much time passes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));
    $window = SyncWindow::freeze(CarbonImmutable::parse('2026-03-01 09:30:00', 'UTC'), overlapMinutes: 10);
    $before = $window->toQuery();

    $this->travel(3)->hours();

    expect($window->toQuery())->toBe($before);
});

it('truncates cursor_to to whole seconds so the stored cursor equals the queried boundary', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00.987654', 'UTC'));

    $window = SyncWindow::freeze(null, overlapMinutes: 10);

    expect($window->modifiedBefore->format('Y-m-d H:i:s.u'))->toBe('2026-03-01 10:00:00.000000');
});

it('has no lower bound on the very first sync (no stored cursor)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));

    $window = SyncWindow::freeze(null, overlapMinutes: 10);

    expect($window->modifiedAfter)->toBeNull()
        ->and($window->toQuery())->not->toHaveKey('modified_after')
        ->and($window->toQuery())->toHaveKey('modified_before');
});

it('takes the overlap from config(woo.overlap_minutes) when not given', function () {
    config(['woo.overlap_minutes' => 10]);
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));

    $window = SyncWindow::freeze(CarbonImmutable::parse('2026-03-01 09:30:00', 'UTC'));

    expect($window->modifiedAfter?->toIso8601String())->toBe('2026-03-01T09:20:00+00:00');
});

it('renders the PRD query: modified_after, modified_before, orderby=modified, order=asc — in UTC with dates_are_gmt', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));

    $window = SyncWindow::freeze(CarbonImmutable::parse('2026-03-01 09:30:00', 'UTC'), overlapMinutes: 10);

    expect($window->toQuery())->toBe([
        'modified_after' => '2026-03-01T09:20:00',
        'modified_before' => '2026-03-01T10:00:00',
        'orderby' => 'modified',
        'order' => 'asc',
        'dates_are_gmt' => 'true',
    ]);
});

it('renders UTC even when the cursor was built in another timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));

    $window = SyncWindow::freeze(CarbonImmutable::parse('2026-03-01 13:00:00', 'Asia/Tehran'), overlapMinutes: 10);

    expect($window->toQuery()['modified_after'])->toBe('2026-03-01T09:20:00');
});

it('rejects a window whose lower bound is not before its upper bound', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));

    SyncWindow::freeze(CarbonImmutable::parse('2026-03-01 10:30:00', 'UTC'), overlapMinutes: 10);
})->throws(InvalidArgumentException::class);
