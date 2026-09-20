<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P3-05 boundary. Three read endpoints and two writes of Customer 360, all in routes/internal.php behind auth + customers.view:
|   GET  customers/{customer}/orders   -> CustomerOrdersController   -> Customers\CustomerOrdersService   (reads `orders`)
|   GET  customers/{customer}/products -> CustomerProductsController -> Customers\CustomerProductsService (reads `order_items` + `orders`)
|   GET  customers/{customer}/notes    -> CustomerNotesController@index   -> Customers\CustomerNotesService
|   POST customers/{customer}/notes    -> ...@store    (+ customers.note)  -> CustomerNotesService::store   (note + event, one transaction)
|   DELETE customers/{customer}/notes/{note} -> ...@destroy               -> CustomerNotesService::destroy (author or manage_notes)
| The orders and products services read other modules' TABLES with the query builder (the documented data dependency) and never
| `use` a class of the Orders module. A note's text never enters customer_events.payload. Controllers hold no logic.
*/

function opbFile(string $relative): string
{
    return Scanner::root().'/'.$relative;
}

/** A source file with its formatting normalised — whitespace collapsed, no space inside brackets, no trailing commas — so an assertion pins content, not the formatter's line breaks. */
function opbSource(string $relative): string
{
    $source = (string) file_get_contents(opbFile($relative));
    $source = (string) preg_replace('/\s+/', ' ', $source);
    $source = (string) preg_replace('/,\s*([)\]}])/', '$1', $source);
    $source = (string) preg_replace('/\(\s+/', '(', $source);

    return (string) preg_replace('/\s+\)/', ')', $source);
}

const OPB_ORDERS = 'app/Modules/Customers/Services/CustomerOrdersService.php';
const OPB_PRODUCTS = 'app/Modules/Customers/Services/CustomerProductsService.php';
const OPB_NOTES = 'app/Modules/Customers/Services/CustomerNotesService.php';

/** @return list<string> */
function opbControllers(): array
{
    return array_map(opbFile(...), [
        'app/Http/Controllers/Customers/CustomerOrdersController.php',
        'app/Http/Controllers/Customers/CustomerProductsController.php',
        'app/Http/Controllers/Customers/CustomerNotesController.php',
    ]);
}

/** @return list<string> */
function opbReaders(): array
{
    return [opbFile(OPB_ORDERS), opbFile(OPB_PRODUCTS)];
}

it('has every file of the orders, products and notes tabs, and the two migrations are named as intended', function () {
    $files = [
        ...opbControllers(), ...opbReaders(), opbFile(OPB_NOTES),
        opbFile('app/Http/Requests/Customers/CustomerPageRequest.php'),
        opbFile('app/Http/Requests/Customers/NoteStoreRequest.php'),
        opbFile('app/Http/Requests/Customers/NoteDestroyRequest.php'),
        opbFile('app/Modules/Customers/Support/PageCursor.php'),
        opbFile('app/Modules/Customers/Support/CursorPage.php'),
        opbFile('app/Modules/Customers/Support/NoteRow.php'),
        opbFile('database/migrations/2026_09_20_140000_harden_customer_notes_table.php'),
        opbFile('database/migrations/2026_09_20_140100_add_note_deleted_to_customer_events_type_check.php'),
        opbFile('resources/js/components/customers/CustomerTabs.tsx'),
        opbFile('resources/js/components/customers/CustomerNotes.tsx'),
        opbFile('resources/js/hooks/use-cursor-list.ts'),
    ];

    expect(array_map('is_file', $files))->each->toBeTrue();
});

// ================================================================== controllers

it('keeps every controller free of queries, models, cursor handling, permission checks, config and control flow — one service each', function () {
    foreach (opbControllers() as $controller) {
        $code = Scanner::phpCode($controller);

        expect(Scanner::violations([$controller], [
            '/\bDB::/',
            '/::(query|where|find|findOrFail|create|firstOrCreate|insert|upsert|all|count|paginate)\s*\(/',
            '/->(where|update|delete|save|insert|upsert|orderBy|latest|select|create|filter|map)\s*\(/',
            '/App\\\\Modules\\\\\w+\\\\Models\\\\/',
            '/\b(Log|Queue|Cache|Redis|Http|Auth|Gate)::/',
            '/\bconfig\s*\(|\benv\s*\(|\bauth\s*\(|\bcan\s*\(|->can\(|->allows\(|PermissionService|authorize\s*\(/',
            '/\bif\s*\(|\bforeach\s*\(|\bmatch\s*\(|\bswitch\s*\(|\btry\b|\bcatch\b|\?\?|\?:/',
            '/base64|json_decode|json_encode|PageCursor|(en|de)codeCursor/i',
        ]))->toBe([], basename($controller));
        preg_match_all('/use App\\\\Modules\\\\\w+\\\\Services\\\\\w+;/', $code, $services);
        expect($services[0])->toHaveCount(1, basename($controller));
    }
});

it('shapes a controller response only by returning what the service gives: JSON, 201 for a store, 204 for a delete', function () {
    $notes = Scanner::phpCode(opbFile('app/Http/Controllers/Customers/CustomerNotesController.php'));

    expect(substr_count($notes, 'response()->json('))->toBe(2) // index and store
        ->and($notes)->toContain(', 201)')
        ->and($notes)->toContain('response()->noContent()')
        ->and(Scanner::phpCode(opbFile('app/Http/Controllers/Customers/CustomerOrdersController.php')))->toContain('response()->json(')
        ->and(Scanner::phpCode(opbFile('app/Http/Controllers/Customers/CustomerProductsController.php')))->toContain('response()->json(');
});

// ================================================================== routes

it('registers the five routes with their names, numeric ids, and their permissions — writing a note also needs customers.note', function () {
    $routes = Scanner::phpCode(opbFile('routes/internal.php'));

    expect($routes)->toContain("Route::middleware(['auth', 'permission:customers,view'])")
        ->and($routes)->toMatch("/Route::get\('customers\/\{customer\}\/orders', CustomerOrdersController::class\)->whereNumber\('customer'\)->name\('customers\.orders'\)/")
        ->and($routes)->toMatch("/Route::get\('customers\/\{customer\}\/products', CustomerProductsController::class\)->whereNumber\('customer'\)->name\('customers\.products'\)/")
        ->and($routes)->toMatch("/Route::get\('customers\/\{customer\}\/notes', \[CustomerNotesController::class, 'index'\]\)->whereNumber\('customer'\)->name\('customers\.notes\.index'\)/")
        ->and($routes)->toMatch("/Route::delete\('customers\/\{customer\}\/notes\/\{note\}', \[CustomerNotesController::class, 'destroy'\]\)->whereNumber\(\['customer', 'note'\]\)->name\('customers\.notes\.destroy'\)/")
        ->and($routes)->toMatch("/Route::post\('customers\/\{customer\}\/notes', \[CustomerNotesController::class, 'store'\]\)->middleware\('permission:customers,note'\)->whereNumber\('customer'\)->name\('customers\.notes\.store'\)/");
});

// ================================================================== the orders and products readers

it('never `use`s a class of the Orders module (or any other module besides Customers and Core) — they read the tables, not the code', function () {
    expect(Scanner::violations(opbReaders(), [
        '/App\\\\Modules\\\\(Orders|Metrics|Catalog|Sync|Segments|Analytics|Ai|Core)\\\\/',
    ]))->toBe([]);
});

it('reads only, with the query builder and bindings: no write, no raw SQL, no dispatch, no log, no config', function () {
    expect(Scanner::violations(opbReaders(), [
        '/->(create|update|delete|forceDelete|save|insert|insertOrIgnore|upsert|increment|decrement|truncate|flush|push)\s*\(/',
        '/::(create|updateOrCreate|firstOrCreate|insert|upsert|truncate|dispatch)\s*\(/',
        '/\bDB::(statement|unprepared|update|insert|delete|transaction|select|raw)/',
        '/\b(whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|fromRaw|joinRaw|orWhereRaw)\s*\(|new\s+Expression/',
        '/\b(Log|Queue|Cache|Redis|Http)::|\blogger\s*\(|\bconfig\s*\(|\benv\s*\(/',
    ]))->toBe([]);
});

it('names only orders and order_items besides customers — never a table that holds audit, identity or note data', function () {
    $tables = [];

    foreach (opbReaders() as $file) {
        preg_match_all('/(?:DB::table|->join)\(\s*\'(\w+)(?: as \w+)?\'/', Scanner::phpCode($file), $named);
        array_push($tables, ...$named[1]);
    }

    expect(array_values(array_unique($tables)))->toEqualCanonicalizing(['orders', 'order_items'])
        ->and(Scanner::violations(opbReaders(), ['/phone_reveal_logs|customer_identities|customer_addresses|audit_logs|identity_conflicts|customer_notes|customer_metrics|users/']))->toBe([]);
});

it('counts a purchase from the stored is_realized flag and never from an order status string', function () {
    $products = Scanner::phpCode(opbFile(OPB_PRODUCTS));

    expect($products)->toContain("'o.is_realized', true")
        ->and(Scanner::violations(opbReaders(), ['/[\'"](completed|processing|pending|cancelled|refunded|failed|on-hold)[\'"]/']))->toBe([]);
});

it('groups products by the snapshot name, not by product_id', function () {
    $products = Scanner::phpCode(opbFile(OPB_PRODUCTS));

    expect($products)->toContain("\$key = 'n:'.\$name;")
        ->and($products)->toContain('$line->name_snapshot')
        ->and($products)->not->toMatch('/product_id/');
});

it('orders product names with strcmp — never <=> on names, which compares numeric-looking strings as numbers', function () {
    $products = Scanner::phpCode(opbFile(OPB_PRODUCTS));

    expect($products)->toContain("strcmp(\$a['name'], \$b['name'])")
        ->and($products)->toContain("strcmp(\$g['name'], \$name) > 0")
        ->and($products)->not->toMatch("/\\['name'\\]\\s*<=>|<=>\\s*\\$\\w+\\['name'\\]/");
});

it('leaves money exactly as stored: int Toman, nothing divided, rounded or floated', function () {
    expect(Scanner::violations(opbReaders(), [
        '/\(float\)|\(double\)|floatval|\bround\s*\(|\bfloor\s*\(|\bceil\s*\(|\bintdiv\s*\(|number_format|\/\s*10\b/',
    ]))->toBe([])
        ->and(Scanner::phpCode(opbFile(OPB_ORDERS)))->toContain("'total' => (int) \$row->total");
});

it('sends every date as Jalali (through TehranDateTime) and ISO (UTC) — never the raw stored value', function () {
    $orders = Scanner::phpCode(opbFile(OPB_ORDERS));
    $products = Scanner::phpCode(opbFile(OPB_PRODUCTS));

    expect($orders)->toContain("'ordered_at_jalali' => TehranDateTime::format(\$at)")
        ->and($orders)->toContain("'ordered_at_iso' => \$at->utc()->toIso8601ZuluString()")
        ->and($products)->toContain("'last_ordered_at_jalali' => TehranDateTime::format(")
        ->and($products)->toContain("'last_ordered_at_iso' =>")
        ->and(Scanner::violations(opbReaders(), ['/->format\s*\(\s*[\'"](?!Y-m-d H:i:sP)/', '/\bdate\s*\(|->toDateTimeString|->toDateString|->toIso8601String\b/']))->toBe([])
        // The bare key appears only on cursor lines (which the browser cannot read) and never in a row.
        ->and(array_filter(explode("\n", $orders), fn (string $line) => str_contains($line, "'ordered_at' =>") && ! str_contains($line, 'PageCursor::') && ! str_contains($line, "'instant'")))->toBe([])
        ->and(array_filter(explode("\n", $products), fn (string $line) => str_contains($line, "'last_ordered_at' =>") && ! str_contains($line, 'PageCursor::') && ! str_contains($line, "'instant'")))->toBe([]);
});

it('pages orders and notes with ONE bound row comparison — an index range start, nothing built from a string', function () {
    expect(Scanner::phpCode(opbFile(OPB_ORDERS)))->toContain("->whereRowValues(['ordered_at', 'id'], '<', [")
        ->and(Scanner::phpCode(opbFile(OPB_NOTES)))->toContain("->whereRowValues(['created_at', 'id'], '<', [")
        ->and(Scanner::phpCode(opbFile(OPB_ORDERS)))->toContain('->orderByDesc(\'ordered_at\')')
        ->and(Scanner::phpCode(opbFile(OPB_ORDERS)))->toContain('->limit($limit + 1)')
        ->and(Scanner::phpCode(opbFile(OPB_NOTES)))->toContain('->limit($limit + 1)');
});

it('reads a cursor ONLY through PageCursor: base64 lives in that one file and nowhere else in the feature', function () {
    $others = array_filter(
        Scanner::phpFiles(['app/Modules/Customers', 'app/Http/Controllers/Customers', 'app/Http/Requests/Customers']),
        fn (string $file) => ! str_ends_with($file, 'Support/PageCursor.php'),
    );

    foreach ([OPB_ORDERS, OPB_PRODUCTS, OPB_NOTES] as $service) {
        expect(Scanner::phpCode(opbFile($service)))->toContain('PageCursor::encode(');
    }

    expect(Scanner::violations(array_values($others), ['/base64_(en|de)code|json_decode/i']))->toBe([])
        ->and(Scanner::phpCode(opbFile('app/Modules/Customers/Support/ResolvesCursorPages.php')))->toContain('PageCursor::decode(');
});

// ================================================================== the notes service: the body never enters the timeline

it('writes a timeline event with the note\'s ID only: the payload is {note_id}, and no line that builds an event mentions the body', function () {
    $code = Scanner::phpCode(opbFile(OPB_NOTES));
    preg_match('/private function record\(.*?\n    }\n/s', $code, $record);

    expect(substr_count($code, "'payload' =>"))->toBe(1)
        ->and($code)->toContain("'payload' => ['note_id' => \$noteId]")
        ->and($record[0] ?? '')->not->toBe('')
        ->and($record[0])->not->toMatch('/body|->note\b|\$note->/i')
        // record() is handed an id and a type — never a note, never a body.
        ->and($code)->toContain('private function record(int $customerId, CustomerEventType $type, int $noteId): void')
        ->and($code)->not->toMatch('/->record\([^)]*body/i');
});

it('writes the note and its event inside ONE DB::transaction, in store and in destroy', function () {
    $code = Scanner::phpCode(opbFile(OPB_NOTES));

    expect(substr_count($code, 'DB::transaction('))->toBe(2);
    preg_match_all('/DB::transaction\(function[^{]*\{(.*?)\n        \}\);/s', $code, $blocks);

    expect($blocks[1])->toHaveCount(2);

    foreach ($blocks[1] as $block) {
        expect($block)->toContain('$this->record(');
    }

    expect($blocks[1][0])->toContain('CustomerNote::query()->create(')
        ->and($blocks[1][1])->toContain('$note->delete()');
});

it('lets the note\'s author (while they hold customers.note) or a customers.manage_notes holder delete — nobody else', function () {
    $code = Scanner::phpCode(opbFile(OPB_NOTES));

    expect($code)->toContain("allows(\$actor, 'customers', 'manage_notes')")
        ->and($code)->toContain("\$note->user_id === \$actor->id && \$this->permissions->allows(\$actor, 'customers', 'note')")
        ->and($code)->toContain('throw new AuthorizationException(')
        // The check runs BEFORE the transaction that deletes.
        ->and(strpos($code, 'mayDelete($note, $actor)'))->toBeLessThan((int) strpos($code, 'DB::transaction(function () use ($note)'));
});

it('takes the author from the session, the customer from the URL, and never the request body', function () {
    $store = Scanner::phpCode(opbFile('app/Http/Requests/Customers/NoteStoreRequest.php'));
    $destroy = Scanner::phpCode(opbFile('app/Http/Requests/Customers/NoteDestroyRequest.php'));

    expect($store)->toContain('$this->user()')
        ->and($store)->toContain("\$this->route('customer')")
        ->and($store)->toContain("'body' => ['required', 'string', 'max:2000']")
        ->and($store)->not->toMatch('/input\(|->all\(|validated\(|only\(|user_id|author_id|customer_id/')
        // A note is looked up UNDER its customer: a note id under another customer's URL is a 404.
        ->and($destroy)->toContain("where('customer_id', \$customer->id)")
        ->and($destroy)->toContain('->findOrFail(');
});

it('never logs, and never hands a note, a name or a phone to anything but the database', function () {
    expect(Scanner::violations([opbFile(OPB_NOTES), opbFile('app/Modules/Customers/Support/NoteRow.php'), ...opbControllers()], [
        '/\b(Log|Cache|Queue|Http|Notification|Mail)::|\blogger\s*\(|\binfo\s*\(|\berror_log\s*\(|->(info|warning|error|debug|notice)\s*\(/',
        '/phone_normalized|phone_raw|PhoneMask|PhoneNormalizer|\bemail\b/i',
    ]))->toBe([]);
});

it('shows a note author as an id and a name only — never an email', function () {
    $row = Scanner::phpCode(opbFile('app/Modules/Customers/Support/NoteRow.php'));
    $service = Scanner::phpCode(opbFile(OPB_NOTES));

    expect($row)->toContain("'author_id' => \$n->user_id")
        ->and($row)->toContain("'author_name' => \$n->author?->name")
        ->and($service)->toContain("->with('author:id,name')");
});

// ================================================================== the React side

it('writes the new components in strict TypeScript, renders a note as text, and keeps fetch out of the page', function () {
    $files = [
        opbFile('resources/js/components/customers/CustomerTabs.tsx'),
        opbFile('resources/js/components/customers/CustomerNotes.tsx'),
        opbFile('resources/js/hooks/use-cursor-list.ts'),
        opbFile('resources/js/lib/http.ts'),
    ];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        expect($source)->not->toMatch('/:\s*any\b|\bas\s+any\b|<any>|Array<any>|@ts-(ignore|nocheck|expect-error)/', basename($file))
            ->and($source)->not->toContain('dangerouslySetInnerHTML')
            ->and($source)->not->toMatch('/console\.(log|debug|info)|\bdebugger\b|\binnerHTML\b/')
            ->and($source)->not->toMatch('/\batob\s*\(|\bbtoa\s*\(|JSON\.parse\s*\(|base64/i')
            ->and($source)->not->toMatch('/[\'"`]\/customers\//', basename($file));
    }

    expect(opbSource('resources/js/pages/customers/show.tsx'))->not->toMatch('/\b(fetch|axios|XMLHttpRequest)\s*\(/')
        ->and(opbSource('resources/js/components/customers/CustomerNotes.tsx'))->toContain('{note.body}');
});

it('fetches a tab only after it is chosen: the panels mount on the first selection, and the page fetches nothing by itself', function () {
    $tabs = opbSource('resources/js/components/customers/CustomerTabs.tsx');
    $page = opbSource('resources/js/pages/customers/show.tsx');
    $hook = opbSource('resources/js/hooks/use-cursor-list.ts');

    expect($tabs)->toContain('opened.includes(tab.id)')            // a panel exists only for a tab that was opened
        ->and($tabs)->not->toContain('useState<TabId | null>')      // the state lives in the page, not here
        ->and($page)->toContain('useState<TabId | null>(null)')     // nothing selected at first
        ->and($page)->toContain('useState<TabId[]>([])')            // nothing opened at first
        ->and($page)->toContain('setOpenedTabs(')
        ->and($hook)->toContain('loadFirst: () => fetchPage(null)') // the hook fetches nothing until asked
        ->and($hook)->not->toMatch('/useEffect\(/');
});

it('uses the generated routes for every address, and lets the cursor through untouched', function () {
    $tabs = opbSource('resources/js/components/customers/CustomerTabs.tsx');
    $notes = opbSource('resources/js/components/customers/CustomerNotes.tsx');

    expect($tabs)->toContain("from '@/routes/customers'")
        ->and($tabs)->toContain('orders.url(customerId, cursor === null ? {} : { query: { cursor } })')->toContain('products.url(customerId, cursor === null ? {} : { query: { cursor } })')
        ->and($notes)->toContain("from '@/routes/customers/notes'")
        ->and($notes)->toContain('index.url(customerId, cursor === null ? {} : { query: { cursor } })')->toContain('store.url(customerId)')->toContain('destroy.url({ customer: customerId, note: note.id })')
        ->and($tabs)->toContain('{ query: { cursor } }')
        ->and($notes)->toContain('{ query: { cursor } }');
});

it('shows the delete button only to the note\'s author (who still may write) or a customers.manage_notes holder, and the form only to a writer — UX, the endpoints enforce', function () {
    $notes = opbSource('resources/js/components/customers/CustomerNotes.tsx');

    expect($notes)->toContain("can('customers', 'manage_notes')")
        ->and($notes)->toContain("can('customers', 'note')")
        ->and($notes)->toContain('canManage || (canWrite && note.author_id === auth.user.id)')
        ->and($notes)->toContain('{canWrite && (')
        ->and($notes)->toContain('{mayDelete(note) &&')
        ->and($notes)->toContain('maxLength={BODY_MAX}')
        ->and($notes)->toContain('const BODY_MAX = 2000;')
        // A write carries the CSRF token.
        ->and($notes)->toContain('headers: writeHeaders()');
});

it('shows the orders tab columns the task asked for: order number, Jalali date, status badge, Toman amount and a realized badge', function () {
    $tabs = opbSource('resources/js/components/customers/CustomerTabs.tsx');

    expect($tabs)->toContain('order.woo_order_id')
        ->and($tabs)->toContain('order.ordered_at_jalali')
        ->and($tabs)->toContain('<StatusBadge status={order.status}')
        ->and($tabs)->toContain('formatToman(order.total)')
        ->and($tabs)->toContain('order.is_realized')
        ->and($tabs)->toContain('محقق‌شده')
        ->and($tabs)->toContain('product.total_qty')->toContain('product.order_count')->toContain('product.last_ordered_at_jalali')
        ->and($tabs)->not->toContain('ریال');
});
