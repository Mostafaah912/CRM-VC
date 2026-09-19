<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\IdentityConfidence;
use App\Modules\Customers\Enums\IdentityConflictStatus;
use App\Modules\Customers\Enums\IdentitySource;
use App\Modules\Customers\Events\CustomerCreated;
use App\Modules\Customers\Events\IdentityConflictDetected;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerIdentity;
use App\Modules\Customers\Models\IdentityConflict;
use App\Modules\Customers\Services\CustomerIdentityService;
use App\Support\Exceptions\InvalidPhoneException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Arch\Scanner;

/*
| P2-04 (TEST FIRST). PRD §08: the normalized phone is the one identity key. A name mismatch on an
| existing phone never splits the customer, never drops the order and never overwrites the name — it
| becomes a pending row in identity_conflicts for human review. Email is never a matching key.
| Real PostgreSQL only. All names and numbers are synthetic (0900… phones normalize to 989000…).
*/

const ID_PHONE = '09000000101';
const ID_CANON = '989000000101';

function identityService(): CustomerIdentityService
{
    return app(CustomerIdentityService::class);
}

function resolveIdentity(
    ?string $phone = ID_PHONE,
    ?string $first = 'مشتری',
    ?string $last = 'نمونه',
    IdentitySource $source = IdentitySource::WooUser,
    string $sourceId = '11',
    ?int $order = 5001,
): Customer {
    return identityService()->resolve($phone, $first, $last, $source, $sourceId, $order);
}

function customersFor(string $canonical): int
{
    return Customer::withTrashed()->where('phone_normalized', $canonical)->count();
}

// ------------------------------------------------------ identity creation

it('creates exactly one customer, one identity and no conflict for a new phone', function () {
    $customer = resolveIdentity();

    expect($customer->exists)->toBeTrue()
        ->and($customer->phone_normalized)->toBe(ID_CANON)
        ->and($customer->first_name)->toBe('مشتری')
        ->and($customer->last_name)->toBe('نمونه')
        ->and($customer->display_name)->toBe('مشتری نمونه')
        ->and($customer->phone_raw_last)->toBe(ID_PHONE)
        ->and($customer->status->value)->toBe('active')
        ->and($customer->metrics_dirty)->toBeTrue()
        ->and(Customer::count())->toBe(1)
        ->and(IdentityConflict::count())->toBe(0);

    $identity = CustomerIdentity::sole();
    expect([$identity->customer_id, $identity->source, $identity->source_id, $identity->confidence])
        ->toBe([$customer->id, IdentitySource::WooUser, '11', IdentityConfidence::High]);
});

it('returns the same customer, and adds nothing, when the same phone is resolved again', function () {
    $first = resolveIdentity();
    $second = resolveIdentity();

    expect($second->id)->toBe($first->id)
        ->and(Customer::count())->toBe(1)
        ->and(CustomerIdentity::count())->toBe(1)
        ->and(IdentityConflict::count())->toBe(0);
});

it('is idempotent across many repeated resolutions with the same name', function () {
    $ids = array_map(fn () => resolveIdentity()->id, range(1, 5));

    expect(array_unique($ids))->toHaveCount(1)
        ->and(Customer::count())->toBe(1)
        ->and(CustomerIdentity::count())->toBe(1)
        ->and(IdentityConflict::count())->toBe(0);
});

it('keeps one identity row per (source, source id) and adds one for a new source of the same customer', function () {
    $customer = resolveIdentity(sourceId: '11');
    resolveIdentity(sourceId: '11');
    $again = resolveIdentity(source: IdentitySource::WooGuestOrder, sourceId: '5002');

    expect($again->id)->toBe($customer->id)
        ->and(CustomerIdentity::where('customer_id', $customer->id)->count())->toBe(2)
        ->and(CustomerIdentity::pluck('source_id')->all())->toEqualCanonicalizing(['11', '5002']);
});

it('leaves an identity that already belongs to another customer where it is, and still resolves by phone', function () {
    $a = resolveIdentity(phone: '09000000101', sourceId: '11');
    $b = resolveIdentity(phone: '09000000102', sourceId: '11');

    expect($b->id)->not->toBe($a->id)
        ->and(CustomerIdentity::sole()->customer_id)->toBe($a->id);
});

// ---------------------------------------------------------- normalization

it('resolves every accepted representation of a phone to the same customer', function (string $representation) {
    $original = resolveIdentity(ID_PHONE);
    $resolved = resolveIdentity($representation);

    expect($resolved->id)->toBe($original->id)
        ->and(customersFor(ID_CANON))->toBe(1)
        ->and($resolved->phone_normalized)->toBe(ID_CANON);
})->with([
    'national' => ['9000000101'],
    'plus 98' => ['+989000000101'],
    '0098' => ['00989000000101'],
    'spaces' => ['0900 000 0101'],
    'dashes' => ['0900-000-0101'],
    'persian digits' => ['۰۹۰۰۰۰۰۰۱۰۱'],
    'arabic-indic digits' => ['٠٩٠٠٠٠٠٠١٠١'],
    'rtl mark' => ["\u{200F}09000000101"],
    'ltr mark' => ["09000000101\u{200E}"],
]);

it('rejects an invalid phone with PhoneNormalizer\'s own exception and writes nothing', function (?string $phone) {
    expect(fn () => resolveIdentity($phone))->toThrow(InvalidPhoneException::class);

    expect(Customer::withTrashed()->count())->toBe(0)
        ->and(CustomerIdentity::count())->toBe(0)
        ->and(IdentityConflict::count())->toBe(0);
})->with([
    'null' => [null],
    'empty' => [''],
    'too short' => ['12345'],
    'landline' => ['02112345678'],
    'not a mobile' => ['08123456789'],
    'letters' => ['abc'],
]);

it('remembers the latest raw phone text without ever creating a second customer', function () {
    resolveIdentity('09000000101');
    $customer = resolveIdentity('+98 900 000 0101');

    expect($customer->fresh()->phone_raw_last)->toBe('+98 900 000 0101')
        ->and(Customer::count())->toBe(1);
});

it('never lets an overlong name or raw phone reject the order', function () {
    $customer = resolveIdentity(phone: '  +98 900 000 0101  ', first: str_repeat('ا', 300), last: str_repeat('ب', 300));

    expect(mb_strlen((string) $customer->first_name))->toBe(80)
        ->and(mb_strlen((string) $customer->last_name))->toBe(80)
        ->and(mb_strlen((string) $customer->display_name))->toBeLessThanOrEqual(160);
});

// ----------------------------------------------- same identity / same name

it('is not a conflict when the same name comes back, however it is typed', function (?string $first, ?string $last) {
    $original = resolveIdentity();
    $again = resolveIdentity(first: $first, last: $last, order: 5002);

    expect($again->id)->toBe($original->id)
        ->and(IdentityConflict::count())->toBe(0)
        ->and(Customer::count())->toBe(1);
})->with([
    'identical' => ['مشتری', 'نمونه'],
    'extra spaces' => ['  مشتری ', '  نمونه  '],
    'arabic letter shapes' => ['مشتري', 'نمونه'],
    'no last name at all' => ['مشتری', null],
    'empty last name' => ['مشتری', ''],
    'no names at all' => [null, null],
]);

it('compares the last name only: a different first name updates, it does not conflict', function () {
    resolveIdentity(first: 'مشتری', last: 'نمونه');
    $customer = resolveIdentity(first: 'دوم', last: 'نمونه', order: 5002);

    expect($customer->first_name)->toBe('دوم')
        ->and($customer->last_name)->toBe('نمونه')
        ->and($customer->display_name)->toBe('دوم نمونه')
        ->and(IdentityConflict::count())->toBe(0);
});

it('updates to the most recent non-empty value and never blanks a name it already has', function () {
    resolveIdentity(first: 'مشتری', last: 'نمونه');
    $customer = resolveIdentity(first: null, last: '', order: 5002);

    expect($customer->first_name)->toBe('مشتری')
        ->and($customer->last_name)->toBe('نمونه')
        ->and($customer->display_name)->toBe('مشتری نمونه');
});

it('fills in a name the customer did not have yet, without a conflict', function () {
    resolveIdentity(first: null, last: null);
    $customer = resolveIdentity(first: 'مشتری', last: 'نمونه', order: 5002);

    expect($customer->last_name)->toBe('نمونه')
        ->and($customer->display_name)->toBe('مشتری نمونه')
        ->and(IdentityConflict::count())->toBe(0);
});

// --------------------------------------------------------- identity conflict

it('records a conflict, keeps the one customer and does not touch its name, when the last name differs', function () {
    $original = resolveIdentity(first: 'مشتری', last: 'نمونه', order: 5001);

    $resolved = resolveIdentity(first: 'شخص', last: 'آزمایشی', source: IdentitySource::WooGuestOrder, sourceId: '5002', order: 5002);

    expect($resolved->id)->toBe($original->id)
        ->and(Customer::count())->toBe(1)
        ->and($resolved->fresh()->first_name)->toBe('مشتری')
        ->and($resolved->fresh()->last_name)->toBe('نمونه')
        ->and($resolved->fresh()->display_name)->toBe('مشتری نمونه');

    $conflict = IdentityConflict::sole();
    expect($conflict->customer_id)->toBe($original->id)
        ->and($conflict->existing_name)->toBe('مشتری نمونه')
        ->and($conflict->incoming_name)->toBe('شخص آزمایشی')
        ->and($conflict->woo_order_id)->toBe(5002)
        ->and($conflict->reason)->toBe(CustomerIdentityService::REASON_LAST_NAME_MISMATCH)
        ->and($conflict->status)->toBe(IdentityConflictStatus::Pending)
        ->and($conflict->resolved_by)->toBeNull()
        ->and($conflict->resolved_at)->toBeNull();
});

it('still attaches the new identity of the order whose name conflicted — the order is never dropped', function () {
    $original = resolveIdentity(sourceId: '11', order: 5001);

    resolveIdentity(last: 'آزمایشی', source: IdentitySource::WooGuestOrder, sourceId: '5002', order: 5002);

    expect(CustomerIdentity::where('customer_id', $original->id)->count())->toBe(2);
});

it('does not repeat a conflict that was already recorded for the same order and name', function () {
    resolveIdentity(order: 5001);
    foreach (range(1, 4) as $ignored) {
        resolveIdentity(last: 'آزمایشی', order: 5002);
    }

    expect(IdentityConflict::count())->toBe(1)
        ->and(Customer::count())->toBe(1);
});

it('does not raise the conflict again once a human has resolved it', function (IdentityConflictStatus $status) {
    resolveIdentity(order: 5001);
    resolveIdentity(last: 'آزمایشی', order: 5002);
    IdentityConflict::query()->update(['status' => $status->value]);

    resolveIdentity(last: 'آزمایشی', order: 5002);

    expect(IdentityConflict::count())->toBe(1)
        ->and(IdentityConflict::sole()->status)->toBe($status);
})->with([
    IdentityConflictStatus::ConfirmedSame,
    // ConfirmedDifferent cannot be stored yet: identity_conflicts.status is varchar(15) but the value is 19
    // characters (PRD §09 contradicts itself). Recorded in ARCHITECTURE.md; a new migration must fix it.
    IdentityConflictStatus::Ignored,
]);

it('records one conflict per order, and one per differing name', function () {
    resolveIdentity(order: 5001);
    resolveIdentity(last: 'آزمایشی', order: 5002);
    resolveIdentity(last: 'آزمایشی', order: 5003);
    resolveIdentity(last: 'دیگری', order: 5003);

    expect(IdentityConflict::count())->toBe(3)
        ->and(Customer::count())->toBe(1);
});

it('dedupes a conflict that has no order context on (customer, incoming name)', function () {
    resolveIdentity(order: null);
    resolveIdentity(last: 'آزمایشی', order: null);
    resolveIdentity(last: 'آزمایشی', order: null);

    expect(IdentityConflict::count())->toBe(1)
        ->and(IdentityConflict::sole()->woo_order_id)->toBeNull();
});

it('keeps conflicts of different customers apart', function () {
    resolveIdentity(phone: '09000000101', order: 5001);
    resolveIdentity(phone: '09000000102', order: 5002);
    resolveIdentity(phone: '09000000101', last: 'آزمایشی', order: 5003);
    resolveIdentity(phone: '09000000102', last: 'آزمایشی', order: 5003);

    expect(IdentityConflict::count())->toBe(2)
        ->and(IdentityConflict::distinct('customer_id')->count('customer_id'))->toBe(2);
});

// ------------------------------------------------------ critical invariant

it('never maps one normalized phone to two customers, whatever mix of formats, names and sources arrives', function () {
    $formats = ['09000000101', '9000000101', '+989000000101', '00989000000101', '0900 000 0101', '۰۹۰۰۰۰۰۰۱۰۱'];
    $lasts = ['نمونه', 'آزمایشی', null, '', 'كريمي', 'نمونه'];
    $ids = [];

    foreach (range(0, 23) as $i) {
        $ids[] = resolveIdentity(
            phone: $formats[$i % 6],
            last: $lasts[$i % 6],
            source: $i % 2 === 0 ? IdentitySource::WooUser : IdentitySource::WooGuestOrder,
            sourceId: (string) (100 + $i),
            order: 6000 + $i,
        )->id;
    }

    expect(array_unique($ids))->toHaveCount(1)
        ->and(customersFor(ID_CANON))->toBe(1)
        ->and(Customer::withTrashed()->count())->toBe(1);
});

it('is also guaranteed by the database: a second row for the same phone is refused', function () {
    resolveIdentity();

    expect(fn () => DB::transaction(fn () => DB::table('customers')->insert([
        'phone_normalized' => ID_CANON,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class);

    expect(customersFor(ID_CANON))->toBe(1);
});

it('survives losing a race: a customer committed between the lookup and the insert is used, not duplicated', function () {
    $injected = false;
    DB::listen(function (QueryExecuted $query) use (&$injected) {
        if (! $injected && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, '"customers"')) {
            $injected = true;
            DB::table('customers')->insert([
                'phone_normalized' => ID_CANON,
                'first_name' => 'مشتری',
                'last_name' => 'نمونه',
                'display_name' => 'مشتری نمونه',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });
    Event::fake([CustomerCreated::class, IdentityConflictDetected::class]);

    $customer = resolveIdentity();

    expect($injected)->toBeTrue()
        ->and(customersFor(ID_CANON))->toBe(1)
        ->and($customer->phone_normalized)->toBe(ID_CANON)
        ->and(CustomerIdentity::sole()->customer_id)->toBe($customer->id);
    Event::assertNotDispatched(CustomerCreated::class);
});

it('resolves to a soft-deleted customer that owns the phone instead of failing on the unique index, without restoring it', function () {
    $deleted = resolveIdentity();
    $deleted->delete();

    $resolved = resolveIdentity(order: 5002);

    expect($resolved->id)->toBe($deleted->id)
        ->and($resolved->trashed())->toBeTrue()
        ->and(customersFor(ID_CANON))->toBe(1);
});

// ------------------------------------------------------------- transaction

it('rolls the whole resolution back when it cannot finish: no half-written identity, name or phone', function () {
    $original = resolveIdentity(phone: '09000000101', sourceId: '11');
    Event::listen('eloquent.creating: '.IdentityConflict::class, fn () => throw new RuntimeException('boom'));
    Event::fake([CustomerCreated::class, IdentityConflictDetected::class]);

    expect(fn () => resolveIdentity(phone: '+98 900 000 0101', last: 'آزمایشی', source: IdentitySource::WooGuestOrder, sourceId: '5002', order: 5002))
        ->toThrow(RuntimeException::class, 'boom');

    expect(CustomerIdentity::count())->toBe(1)
        ->and(IdentityConflict::count())->toBe(0)
        ->and($original->fresh()->phone_raw_last)->toBe('09000000101')
        ->and($original->fresh()->last_name)->toBe('نمونه');
    Event::assertNotDispatched(IdentityConflictDetected::class);
});

// ------------------------------------------------------------------ events

it('announces a new customer once, and only when it was really created', function () {
    Event::fake([CustomerCreated::class, IdentityConflictDetected::class]);

    $customer = resolveIdentity();
    resolveIdentity(order: 5002);

    Event::assertDispatchedTimes(CustomerCreated::class, 1);
    Event::assertDispatched(CustomerCreated::class, fn (CustomerCreated $e) => $e->customerId === $customer->id);
    Event::assertNotDispatched(IdentityConflictDetected::class);
});

it('announces a conflict once, with ids only — never a name or a phone', function () {
    Event::fake([CustomerCreated::class, IdentityConflictDetected::class]);
    $customer = resolveIdentity(order: 5001);

    resolveIdentity(last: 'آزمایشی', order: 5002);
    resolveIdentity(last: 'آزمایشی', order: 5002);

    $conflict = IdentityConflict::sole();
    Event::assertDispatchedTimes(IdentityConflictDetected::class, 1);
    Event::assertDispatched(IdentityConflictDetected::class, fn (IdentityConflictDetected $e) => [$e->customerId, $e->conflictId, $e->wooOrderId] === [$customer->id, $conflict->id, 5002]);

    foreach ([CustomerCreated::class, IdentityConflictDetected::class] as $class) {
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            expect((string) $property->getType())->toBeIn(['int', '?int']);
        }
    }
});

// -------------------------------------------------------- what it must not be

it('has no way to match on email, and never reads or writes one', function () {
    $file = Scanner::root().'/app/Modules/Customers/Services/CustomerIdentityService.php';
    $parameters = array_map(fn (ReflectionParameter $p) => $p->getName(), (new ReflectionMethod(CustomerIdentityService::class, 'resolve'))->getParameters());

    expect($parameters)->not->toContain('email')
        ->and(Scanner::violations([$file], ['/email/i']))->toBe([]);

    Customer::factory()->create(['phone_normalized' => '989000000199', 'email' => 'someone@example.test']);
    $resolved = resolveIdentity(phone: '09000000102');

    expect($resolved->phone_normalized)->toBe('989000000102')->and($resolved->email)->toBeNull()
        ->and(Customer::count())->toBe(2);
});

it('normalizes every phone through PhoneNormalizer and never parses one itself', function () {
    $file = Scanner::root().'/app/Modules/Customers/Services/CustomerIdentityService.php';

    expect((string) file_get_contents($file))->toContain('PhoneNormalizer::normalize(')
        ->and(Scanner::violations([$file], ['/preg_(replace|match)\w*\s*\(.*(phone|\\\\d)/i', '/\b(substr|ltrim|str_replace|trim)\s*\(\s*\$phone/']))->toBe([]);
});

it('stays inside its module boundary: Core only, no raw SQL, no other module, no network', function () {
    $file = Scanner::root().'/app/Modules/Customers/Services/CustomerIdentityService.php';

    expect(Scanner::violations([$file], [
        '/App\\\\Modules\\\\(?!Customers\\\\)\w+\\\\/',
        '/\bDB::(raw|statement|select|unprepared)\b/',
        '/\b(whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw)\s*\(/',
        '/\bHttp::|GuzzleHttp|Redis::/',
    ]))->toBe([])
        ->and((new ReflectionClass(CustomerIdentityService::class))->isFinal())->toBeTrue();
});
