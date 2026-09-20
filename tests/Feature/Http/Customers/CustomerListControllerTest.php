<?php

declare(strict_types=1);

use App\Modules\Core\Models\Permission;
use App\Modules\Customers\Models\Customer;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P3-01 — GET /customers (routes/internal.php): behind auth + customers.view. The controller validates, calls ONE service and
| renders; masking, search and filters are the service's. Phones are masked in the RESPONSE ITSELF (not merely hidden by the
| page) for EVERYONE — a holder of customers.view_full_phone reveals one number at a time through POST /customers/{id}/reveal-phone (P3-02, audited). Data: the deterministic DemoDataSeeder, test database only.
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
    app(DemoDataSeeder::class)->run();
});

/** @param  list<string>  $keys */
function clPage($test, string $query = '', array $keys = ['customers.view']): array
{
    return $test->actingAs(Fx::userWith(...$keys))->get('/customers'.$query)->assertOk()->inertiaProps();
}

// ================================================================== access

it('redirects a guest to login', function () {
    $this->get('/customers')->assertRedirect(route('login'));
});

it('forbids a signed-in user without customers.view — even one who can see full phones or the system pages', function () {
    $this->actingAs(Fx::userWith())->get('/customers')->assertForbidden();
    $this->actingAs(Fx::userWith('customers.view_full_phone', 'system.view', 'audit.view', 'orders.view'))->get('/customers')->assertForbidden();
});

it('lets an explicit deny override beat the role grant', function () {
    $user = Fx::userWith('customers.view');
    $permission = Permission::query()->where('module', 'customers')->where('action', 'view')->firstOrFail();
    $user->permissionOverrides()->create(['permission_id' => $permission->id, 'effect' => 'deny']);

    $this->actingAs($user)->get('/customers')->assertForbidden();
});

it('renders the customers component with the page of customers, the echoed filters and the filter options', function () {
    $this->actingAs(Fx::userWith('customers.view'))
        ->get('/customers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/index')
            ->has('customers.data', 25)
            ->where('customers.total', 50)
            ->where('filters', ['search' => null, 'status' => null, 'lifecycle_stage' => null, 'province' => null, 'city' => null, 'needs_review' => null, 'first_seen_from' => null, 'first_seen_to' => null])
            ->has('options.statuses')
            ->has('options.lifecycle_stages')
            ->has('options.provinces')
            ->has('options.cities'),
        );
});

// ================================================================== the response never carries more than the list shows

it('sends only the documented row fields, and no email, raw phone, first or last name, or metric', function () {
    $page = Fx::pageProps(clPage($this));

    expect(array_keys($page))->toEqualCanonicalizing(['customers', 'filters', 'options'])
        ->and(array_keys($page['customers']['data'][0]))->toEqualCanonicalizing(['id', 'display_name', 'phone', 'status', 'lifecycle_stage', 'province', 'city', 'first_seen_at', 'needs_review'])
        ->and(array_intersect(Fx::keysDeep($page['customers']), [
            'email', 'phone_raw_last', 'phone_normalized', 'first_name', 'last_name', 'metrics_dirty', 'deleted_at',
            'rfm', 'r_score', 'f_score', 'm_score', 'rfm_segment', 'churn_risk_level', 'churn_reason', 'clv_estimated', 'clv_confidence', 'total_orders', 'total_revenue',
        ]))->toBe([]);
});

// ================================================================== phone masking, through the real response

it('masks every phone in the response, for a viewer without customers.view_full_phone', function () {
    $body = clPage($this);
    $byId = Customer::query()->pluck('phone_normalized', 'id');

    foreach ($body['customers']['data'] as $row) {
        expect($row['phone'])->toBe('********'.substr($byId[$row['id']], -4));
    }

    // No full number anywhere in the props — the page could not "unhide" what is not there.
    expect(preg_match('/98\d{10}/', json_encode($body['customers'])))->toBe(0)
        ->and(preg_match('/98\d{10}/', json_encode($body['options'])))->toBe(0);
});

it('masks every phone in the response for a holder of customers.view_full_phone too — they reveal one number at a time, audited', function () {
    $holder = clPage($this, keys: ['customers.view', 'customers.view_full_phone']);
    $plain = clPage($this);
    $byId = Customer::query()->pluck('phone_normalized', 'id');

    foreach ($holder['customers']['data'] as $row) {
        expect($row['phone'])->toBe('********'.substr($byId[$row['id']], -4));
    }

    expect(preg_match('/98\d{10}/', json_encode($holder['customers'])))->toBe(0)
        ->and($holder['customers']['data'])->toBe($plain['customers']['data']);
});

it('never gives the full number back through the search box', function () {
    $customer = Customer::query()->where('phone_normalized', DemoDataSeeder::phone(4))->firstOrFail();

    $body = clPage($this, '?search='.urlencode('0'.substr($customer->phone_normalized, 2)));

    expect($body['customers']['data'])->toHaveCount(1)
        ->and($body['customers']['data'][0]['phone'])->toBe('********'.substr($customer->phone_normalized, -4))
        ->and(json_encode($body['customers']))->not->toContain($customer->phone_normalized);
});

// ================================================================== search

it('searches by a Persian name from the query string', function () {
    $expected = Customer::query()->get()->filter(fn (Customer $c) => str_contains((string) $c->display_name, 'مریم'))->count();

    $body = clPage($this, '?search='.urlencode('مریم'));

    expect($expected)->toBeGreaterThan(0)
        ->and($body['customers']['total'])->toBe($expected)
        ->and($body['filters']['search'])->toBe('مریم');
});

it('searches by a phone typed in different formats and finds the same one customer', function (string $typed) {
    $customer = Customer::query()->where('phone_normalized', DemoDataSeeder::phone(11))->firstOrFail();

    $body = clPage($this, '?search='.urlencode($typed));

    expect($body['customers']['data'])->toHaveCount(1)->and($body['customers']['data'][0]['id'])->toBe($customer->id);
})->with(fn () => [
    'national' => ['0'.substr(DemoDataSeeder::phone(11), 2)],
    'plus 98' => ['+'.DemoDataSeeder::phone(11)],
    'persian digits' => [strtr('0'.substr(DemoDataSeeder::phone(11), 2), ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'])],
    'dashes' => ['0'.substr(DemoDataSeeder::phone(11), 2, 3).'-'.substr(DemoDataSeeder::phone(11), 5, 3).'-'.substr(DemoDataSeeder::phone(11), 8)],
]);

it('answers a search that is not a valid phone with a plain result page, never an error', function () {
    $this->actingAs(Fx::userWith('customers.view'))->get('/customers?search='.urlencode('0912abc'))->assertOk();
    $this->actingAs(Fx::userWith('customers.view'))->get('/customers?search='.urlencode('%%__'))->assertOk();
});

// ================================================================== filters from the query string

it('filters by status, lifecycle stage, province, city and needs_review', function () {
    Customer::query()->orderBy('id')->limit(3)->update(['status' => 'blocked', 'lifecycle_stage' => 'loyal', 'needs_review' => true]);
    $blockedIds = Customer::query()->where('status', 'blocked')->pluck('id')->sort()->values()->all();

    $byStatus = clPage($this, '?status=blocked');
    $byStage = clPage($this, '?lifecycle_stage=loyal');
    $byReview = clPage($this, '?needs_review=1');

    expect($byStatus['customers']['total'])->toBe(3)
        ->and(collect($byStatus['customers']['data'])->pluck('id')->sort()->values()->all())->toBe($blockedIds)
        ->and($byStage['customers']['total'])->toBe(3)
        ->and($byReview['customers']['total'])->toBe(3)
        ->and(clPage($this, '?province='.urlencode('تهران'))['customers']['total'])->toBe(10)
        ->and(clPage($this, '?city='.urlencode('مشهد'))['customers']['total'])->toBe(10)
        ->and(clPage($this, '?province='.urlencode('تهران').'&city='.urlencode('مشهد'))['customers']['total'])->toBe(0)
        ->and(clPage($this, '?needs_review=0')['customers']['total'])->toBe(47);
});

it('filters by a Jalali first-seen range, typed with ASCII or Persian digits and either separator', function () {
    Customer::factory()->create(['display_name' => 'ZZ-in-range', 'first_seen_at' => '2026-03-20 20:30:00']); // 1405/01/01 00:00 Tehran

    $ascii = clPage($this, '?first_seen_from=1405/01/01&first_seen_to=1405/01/01');
    $persian = clPage($this, '?first_seen_from='.urlencode('۱۴۰۵-۰۱-۰۱').'&first_seen_to='.urlencode('۱۴۰۵/۰۱/۰۱'));
    $other = clPage($this, '?first_seen_from=1405/01/02&first_seen_to=1405/01/02');

    expect(collect($ascii['customers']['data'])->pluck('display_name')->all())->toContain('ZZ-in-range')
        ->and(collect($persian['customers']['data'])->pluck('display_name')->all())->toContain('ZZ-in-range')
        ->and(collect($other['customers']['data'])->pluck('display_name')->all())->not->toContain('ZZ-in-range');
});

it('echoes the filters as typed so the form keeps its values', function () {
    $body = clPage($this, '?search='.urlencode('علی').'&status=blocked&lifecycle_stage=loyal&province='.urlencode('تهران').'&city='.urlencode('تهران').'&needs_review=1&first_seen_from='.urlencode('۱۴۰۵/۰۱/۰۱').'&first_seen_to=1405-02-01');

    expect($body['filters'])->toBe([
        'search' => 'علی', 'status' => 'blocked', 'lifecycle_stage' => 'loyal', 'province' => 'تهران', 'city' => 'تهران',
        'needs_review' => true, 'first_seen_from' => '۱۴۰۵/۰۱/۰۱', 'first_seen_to' => '1405-02-01',
    ]);
});

it('treats an empty filter value as "not asked"', function () {
    expect(clPage($this, '?search=&status=&lifecycle_stage=&province=&city=&needs_review=&first_seen_from=&first_seen_to=')['customers']['total'])->toBe(50);
});

it('offers the cities of the chosen province only', function () {
    expect(clPage($this)['options']['cities'])->toEqualCanonicalizing(['تهران', 'اصفهان', 'مشهد', 'شیراز', 'تبریز'])
        ->and(clPage($this, '?province='.urlencode('فارس'))['options']['cities'])->toBe(['شیراز']);
});

it('rejects a value it does not understand instead of ignoring it, and never runs the query with it', function (string $query) {
    $this->actingAs(Fx::userWith('customers.view'))->get('/customers'.$query)->assertRedirect()->assertSessionHasErrors();
})->with([
    'unknown status' => ['?status=exploded'],
    'unknown lifecycle stage' => ['?lifecycle_stage=vip'],
    'needs_review not a boolean' => ['?needs_review=maybe'],
    'impossible jalali day' => ['?first_seen_from=1405/13/40'],
    'gregorian-looking day' => ['?first_seen_from=2026-03-21'],
    'range reversed' => ['?first_seen_from=1405/05/01&first_seen_to=1405/01/01'],
    'array where text is expected' => ['?province[]=x'],
    'injection attempt in status' => ["?status=active'%20OR%201=1"],
]);

it('rejects an over-long search', function () {
    $this->actingAs(Fx::userWith('customers.view'))->get('/customers?search='.str_repeat('x', 101))->assertRedirect()->assertSessionHasErrors('search');
});

// ================================================================== pagination

it('serves page 2 as the second block of 25, and keeps the filters in the paging links', function () {
    $first = clPage($this)['customers'];
    $second = clPage($this, '?page=2')['customers'];

    expect($first['data'])->toHaveCount(25)
        ->and($second['data'])->toHaveCount(25)
        ->and($second['current_page'])->toBe(2)
        ->and($second['last_page'])->toBe(2)
        ->and(array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')))->toBe([])
        ->and($first['next_page_url'])->toContain('page=2')
        ->and(clPage($this, '?province='.urlencode('تهران').'&page=1')['customers']['total'])->toBe(10);

    $filtered = clPage($this, '?needs_review=0');
    expect($filtered['customers']['next_page_url'])->toContain('needs_review=0')->toContain('page=2');
});

it('shows an empty page beyond the last, not an error', function () {
    expect(clPage($this, '?page=9')['customers']['data'])->toBe([]);
});

it('does not mutate anything: a page view writes no row', function () {
    $before = [DB::table('customers')->count(), DB::table('audit_logs')->count(), DB::table('customer_identities')->count()];

    clPage($this);

    expect([DB::table('customers')->count(), DB::table('audit_logs')->count(), DB::table('customer_identities')->count()])->toBe($before);
});
