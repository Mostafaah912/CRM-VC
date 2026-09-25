<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\LifecycleStage;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerListService;
use App\Modules\Customers\Support\CustomerListFilters;
use App\Modules\Customers\Support\JalaliDay;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\DB;

/*
| P3-01 — the customer list: search, filters, offset pagination and phone masking, in ONE service (no controller logic).
| Data is the deterministic DemoDataSeeder (50 customers, 5 places, 100 names built from 10 first and 10 last names), written
| to the test database only; the real synced data is never read. Expectations are computed in PHP from the seeded rows, never
| by repeating the query under test. There is deliberately NO filter on customer_metrics (RFM/churn/CLV): that table is
| Sprint 4's and is still empty.
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
    app(DemoDataSeeder::class)->run();
});

/** @param  array<string, mixed>  $filters  the CustomerListFilters arguments, plus an optional `perPage` (default 25) */
function clList(array $filters = [], int $page = 1): array
{
    $perPage = $filters['perPage'] ?? 25;
    unset($filters['perPage']);
    $result = app(CustomerListService::class)->paginate(new CustomerListFilters(...$filters), $page, $perPage);

    return ['rows' => $result->items(), 'paginator' => $result, 'ids' => array_column($result->items(), 'id')];
}

/** @return list<int> ids of every live customer matching a PHP predicate over its model. */
function clExpected(Closure $matches): array
{
    return Customer::query()->get()->filter($matches)->pluck('id')->sort()->values()->all();
}

function clSortedIds(array $ids): array
{
    sort($ids);

    return $ids;
}

// ================================================================== search: name

it('finds customers by a Persian name fragment — whole first name, whole last name, and a piece from the middle of a word', function (string $term) {
    $expected = clExpected(fn (Customer $c) => str_contains((string) $c->display_name, $term));

    $found = clList(['search' => $term, 'perPage' => 100]);

    expect($expected)->not->toBeEmpty()
        ->and(clSortedIds($found['ids']))->toBe($expected);
})->with(['مریم', 'رضایی', 'ریم', 'علی احمدی', 'ی']);

it('finds nothing for a name that nobody has, without an error', function () {
    expect(clList(['search' => 'ناموجودی-قطعی'])['ids'])->toBe([]);
});

it('treats % and _ typed by the user as ordinary characters, never as wildcards', function () {
    $underscore = Customer::factory()->create(['display_name' => 'a_b test']);
    Customer::factory()->create(['display_name' => 'axb test']);

    expect(clList(['search' => 'a_b'])['ids'])->toBe([$underscore->id])
        ->and(clList(['search' => '%'])['ids'])->toBe([])
        ->and(clList(['search' => '_'])['ids'])->toBe([$underscore->id]);
});

it('ignores a search that is empty or only blanks', function (string $blank) {
    expect(clList(['search' => $blank])['paginator']->total())->toBe(50);
})->with(['', '   ', "\t\n"]);

it('never returns a soft-deleted customer', function () {
    $victim = Customer::query()->where('display_name', 'like', '%مریم%')->firstOrFail();
    $victim->delete();

    expect(clList(['search' => 'مریم', 'perPage' => 100])['ids'])->not->toContain($victim->id)
        ->and(clList()['paginator']->total())->toBe(49);
});

// ================================================================== search: phone (any format)

it('finds one customer by their phone typed in any common format', function (Closure $format) {
    $customer = Customer::query()->where('phone_normalized', DemoDataSeeder::phone(7))->firstOrFail();
    $typed = $format($customer->phone_normalized);

    expect(clList(['search' => $typed])['ids'])->toBe([$customer->id]);
})->with([
    'national with leading zero' => [fn (string $f) => '0'.substr($f, 2)],
    'plus 98' => [fn (string $f) => '+'.$f],
    'normalized 98' => [fn (string $f) => $f],
    '0098 prefix' => [fn (string $f) => '00'.$f],
    'ten digits, no zero' => [fn (string $f) => substr($f, 2)],
    'spaces and dashes' => [fn (string $f) => '0'.substr($f, 2, 3).'-'.substr($f, 5, 3).' '.substr($f, 8)],
    'persian digits' => [fn (string $f) => strtr('0'.substr($f, 2), ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'])],
    'arabic-indic digits' => [fn (string $f) => strtr('0'.substr($f, 2), ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩'])],
    'direction marks' => [fn (string $f) => "\u{200F}0".substr($f, 2)."\u{200E}"],
]);

it('returns nothing, and no error, for a valid phone nobody has', function () {
    expect(clList(['search' => '09121112233'])['ids'])->toBe([]);
});

it('falls back to a plain name search — never an error — when the text is not a valid phone', function (string $text) {
    expect(fn () => clList(['search' => $text]))->not->toThrow(Throwable::class);
})->with(['abc', '0912', '12345', 'علی ۰۹۱۲', '0912abc', '+', '۰۹']);

it('matches a name that merely contains digits as a name, when what was typed is not a valid phone', function () {
    $named = Customer::factory()->create(['display_name' => 'Shop 0912 Owner']);

    expect(clList(['search' => 'Shop 0912'])['ids'])->toBe([$named->id]);
});

it('uses the same box for a name or a phone: each finds its own customer', function () {
    $byPhone = Customer::query()->where('phone_normalized', DemoDataSeeder::phone(3))->firstOrFail();
    $byName = Customer::factory()->create(['display_name' => 'کد-ترکیبی']);

    expect(clList(['search' => '0'.substr($byPhone->phone_normalized, 2)])['ids'])->toBe([$byPhone->id])
        ->and(clList(['search' => 'کد-ترکیبی'])['ids'])->toBe([$byName->id]);
});

// ================================================================== filters

it('filters by status', function (CustomerStatus $status) {
    $ids = Customer::query()->orderBy('id')->limit(4)->pluck('id')->all();
    Customer::query()->whereIn('id', $ids)->update(['status' => $status->value]);

    $expected = clExpected(fn (Customer $c) => $c->status === $status);
    $found = clList(['status' => $status, 'perPage' => 100]);

    expect(clSortedIds($found['ids']))->toBe($expected)
        ->and(array_unique(array_column($found['rows'], 'status')))->toBe([$status->value]);
})->with([CustomerStatus::Blocked, CustomerStatus::Anonymized]);

it('filters by lifecycle stage', function (LifecycleStage $stage) {
    Customer::query()->orderBy('id')->limit(6)->get()->each(fn (Customer $c) => $c->update(['lifecycle_stage' => $stage]));

    expect(clSortedIds(clList(['lifecycleStage' => $stage, 'perPage' => 100])['ids']))
        ->toBe(clExpected(fn (Customer $c) => $c->lifecycle_stage === $stage));
})->with([LifecycleStage::Loyal, LifecycleStage::AtRisk, LifecycleStage::Lost]);

it('filters by province, by city, and by both', function () {
    $tehran = clExpected(fn (Customer $c) => $c->province === 'تهران');
    $mashhad = clExpected(fn (Customer $c) => $c->city === 'مشهد');

    expect($tehran)->not->toBeEmpty()
        ->and(clSortedIds(clList(['province' => 'تهران', 'perPage' => 100])['ids']))->toBe($tehran)
        ->and(clSortedIds(clList(['city' => 'مشهد', 'perPage' => 100])['ids']))->toBe($mashhad)
        ->and(clList(['province' => 'تهران', 'city' => 'مشهد'])['ids'])->toBe([]);
});

it('filters by needs_review: flagged only, unflagged only, or both when not asked', function () {
    $flagged = Customer::query()->orderBy('id')->limit(3)->pluck('id')->all();
    Customer::query()->whereIn('id', $flagged)->update(['needs_review' => true]);

    expect(clSortedIds(clList(['needsReview' => true])['ids']))->toBe(clSortedIds($flagged))
        ->and(clList(['needsReview' => false])['paginator']->total())->toBe(47)
        ->and(clList()['paginator']->total())->toBe(50);
});

it('filters by the first-seen range on Tehran calendar days: both ends inclusive, either end optional', function () {
    // 20:29:59Z is 23:59:59 in Tehran (the last second of a Jalali day); 20:30:00Z is the first second of the next one.
    $lastSecond = Customer::factory()->create(['display_name' => 'ZZ-last-second', 'first_seen_at' => '2031-06-01 20:29:59']);
    $firstSecond = Customer::factory()->create(['display_name' => 'ZZ-first-second', 'first_seen_at' => '2031-06-01 20:30:00']);
    $dayOf = fn (Customer $c): string => JalaliDate::format(CarbonImmutable::instance($c->first_seen_at));
    $range = fn (string $from, string $to) => clList(['firstSeenFrom' => JalaliDay::start($from), 'firstSeenBefore' => JalaliDay::nextStart($to)])['ids'];

    $firstDay = $dayOf($lastSecond);
    $secondDay = $dayOf($firstSecond);

    expect($firstDay)->not->toBe($secondDay)
        ->and($range($firstDay, $firstDay))->toContain($lastSecond->id)->not->toContain($firstSecond->id)
        ->and($range($secondDay, $secondDay))->toContain($firstSecond->id)->not->toContain($lastSecond->id)
        ->and($range($firstDay, $secondDay))->toContain($lastSecond->id)->toContain($firstSecond->id)
        ->and(clList(['firstSeenFrom' => JalaliDay::start($secondDay)])['ids'])->toContain($firstSecond->id)->not->toContain($lastSecond->id)
        ->and(clList(['firstSeenBefore' => JalaliDay::nextStart($firstDay)])['ids'])->toContain($lastSecond->id)->not->toContain($firstSecond->id);
});

it('leaves out customers with no first-seen date when a range is asked for', function () {
    $unknown = Customer::factory()->create(['display_name' => 'ZZ-unknown-date', 'first_seen_at' => null]);

    expect(clList(['firstSeenFrom' => JalaliDay::start('1300/01/01')])['ids'])->not->toContain($unknown->id)
        ->and(clList()['paginator']->total())->toBe(51);
});

it('combines every filter with AND, and with the search', function () {
    $ids = Customer::query()->where('province', 'تهران')->orderBy('id')->limit(2)->pluck('id')->all();
    Customer::query()->whereIn('id', $ids)->update(['status' => 'blocked', 'needs_review' => true]);
    $one = Customer::findOrFail($ids[0]);

    $found = clList(['status' => CustomerStatus::Blocked, 'province' => 'تهران', 'needsReview' => true, 'search' => (string) $one->display_name, 'perPage' => 100]);

    expect($found['ids'])->toContain($ids[0])
        ->and(array_diff($found['ids'], $ids))->toBe([]);
});

// ================================================================== pagination

it('pages 25 customers at a time from one stable order, with no repeat and no gap across pages', function () {
    $first = clList();
    $second = clList(page: 2);
    $third = clList(page: 3);

    $all = Customer::query()->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();

    expect($first['rows'])->toHaveCount(25)
        ->and($second['rows'])->toHaveCount(25)
        ->and($third['rows'])->toBe([])
        ->and($first['paginator']->total())->toBe(50)
        ->and($first['paginator']->lastPage())->toBe(2)
        ->and([...$first['ids'], ...$second['ids']])->toBe($all);
});

it('lists newest-created first and breaks ties by id, newest first', function () {
    $same = '2040-01-01 10:00:00';
    Customer::query()->whereIn('id', Customer::query()->orderBy('id')->limit(3)->pluck('id'))->update(['created_at' => $same]);
    $tied = Customer::query()->where('created_at', $same)->orderByDesc('id')->pluck('id')->all();

    expect(array_slice(clList()['ids'], 0, 3))->toBe($tied);
});

it('does not load more than one page of rows', function () {
    DB::enableQueryLog();
    clList();
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q) => str_contains($q, 'from "customers"'));

    expect($queries->every(fn (string $q) => str_contains($q, 'limit') || str_contains($q, 'count(')))->toBeTrue();
});

it('keeps the page inside a filtered set', function () {
    $tehran = clExpected(fn (Customer $c) => $c->province === 'تهران');
    $result = clList(['province' => 'تهران', 'perPage' => 4], page: 2);

    expect($result['paginator']->total())->toBe(count($tehran))
        ->and($result['rows'])->toHaveCount(4)
        ->and(array_diff($result['ids'], $tehran))->toBe([]);
});

// ================================================================== phone masking

it('shows every phone masked — last four digits only — and never a full number: revealing one is the audited endpoint\'s job', function () {
    $rows = clList(['perPage' => 100])['rows'];
    $byId = Customer::query()->pluck('phone_normalized', 'id');

    expect($rows)->toHaveCount(50);

    foreach ($rows as $row) {
        expect($row['phone'])->toBe('********'.substr($byId[$row['id']], -4));
    }

    expect(preg_match('/98\d{10}/', json_encode($rows)))->toBe(0);
});

it('does not leak a number through the search: searching by a phone still shows only the masked form', function () {
    $customer = Customer::query()->where('phone_normalized', DemoDataSeeder::phone(9))->firstOrFail();

    $row = clList(['search' => $customer->phone_normalized])['rows'][0];

    expect($row['id'])->toBe($customer->id)
        ->and($row['phone'])->toBe('********'.substr($customer->phone_normalized, -4));
});

// ================================================================== the row

it('carries only what the list shows — no email, no raw phone, no first/last name, no metric', function () {
    $row = clList()['rows'][0];

    expect(array_keys($row))->toEqualCanonicalizing(['id', 'display_name', 'phone', 'status', 'lifecycle_stage', 'province', 'city', 'first_seen_at', 'needs_review']);
});

it('formats the first-seen day in Jalali, and gives null for a customer that has none', function () {
    $dated = Customer::factory()->create(['display_name' => 'ZZ-dated', 'first_seen_at' => '2026-03-20 20:30:00']);
    $undated = Customer::factory()->create(['display_name' => 'ZZ-undated', 'first_seen_at' => null]);

    $rows = collect(clList(['search' => 'ZZ-', 'perPage' => 10])['rows'])->keyBy('id');

    expect($rows[$dated->id]['first_seen_at'])->toBe('1405/01/01')
        ->and($rows[$undated->id]['first_seen_at'])->toBeNull();
});

it('shows a customer with no display name as null, not an error', function () {
    $nameless = Customer::factory()->create(['display_name' => null]);

    expect(collect(clList(['perPage' => 100])['rows'])->firstWhere('id', $nameless->id)['display_name'])->toBeNull();
});

// ================================================================== filter options

it('offers every status and lifecycle stage, the provinces that exist, and the cities of the chosen province', function () {
    $options = app(CustomerListService::class)->filterOptions(null);

    expect($options['statuses'])->toBe(['active', 'blocked', 'anonymized'])
        ->and($options['lifecycle_stages'])->toBe(['prospect', 'new', 'active', 'repeat', 'loyal', 'at_risk', 'dormant', 'lost'])
        ->and($options['provinces'])->toEqualCanonicalizing(['تهران', 'اصفهان', 'خراسان رضوی', 'فارس', 'آذربایجان شرقی'])
        ->and($options['cities'])->toEqualCanonicalizing(['تهران', 'اصفهان', 'مشهد', 'شیراز', 'تبریز'])
        ->and(app(CustomerListService::class)->filterOptions('خراسان رضوی')['cities'])->toBe(['مشهد']);
});
