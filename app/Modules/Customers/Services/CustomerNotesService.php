<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Models\User;
use App\Modules\Core\Services\PermissionService;
use App\Modules\Customers\Enums\CustomerEventType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerEvent;
use App\Modules\Customers\Models\CustomerNote;
use App\Modules\Customers\Support\CursorPage;
use App\Modules\Customers\Support\NoteRow;
use App\Modules\Customers\Support\PageCursor;
use App\Modules\Customers\Support\ResolvesCursorPages;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Notes on a customer (P3-05): list, add, delete.
 *
 *  - store() and destroy() each write the note AND one customer_events row (`note_added` / `note_deleted`) in ONE transaction,
 *    so a note never exists without its event, nor an event without its note.
 *  - The event payload is {"note_id": <id>} and NEVER the body: a timeline event is shown to every customers.view holder, and the
 *    body is the one thing a note exists to keep to its own list.
 *  - A note is deleted by its author — while that author still holds customers.note — or by anyone who holds
 *    customers.manage_notes. Everyone else gets a 403. PermissionService applies the deny-wins order.
 *  - Nothing here logs: not a body, not a name, not a phone.
 *
 * The list is cursor-paged over (created_at DESC, id DESC) like every Customer 360 list.
 *
 * @phpstan-import-type NoteShape from NoteRow
 */
class CustomerNotesService
{
    use ResolvesCursorPages;

    public const BODY_MAX_LENGTH = 2000;

    public function __construct(private readonly PermissionService $permissions) {}

    /** @return CursorPage<NoteShape> */
    public function pageFor(int $customerId, ?string $cursor = null, ?int $perPage = null): CursorPage
    {
        $customer = Customer::query()->select('id')->findOrFail($customerId);
        $limit = $this->limit($perPage);

        $query = CustomerNote::query()
            ->with('author:id,name')
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1);

        if ($cursor !== null) {
            $position = $this->position($cursor, ['created_at' => 'instant', 'id' => 'id']);

            $query->whereRowValues(['created_at', 'id'], '<', [$position->instant('created_at')->format('Y-m-d H:i:sP'), $position->id('id')]);
        }

        $rows = $query->get(NoteRow::COLUMNS);
        $hasMore = $rows->count() > $limit;
        $shown = $rows->take($limit)->values();
        $last = $shown->last();

        $notes = [];

        foreach ($shown as $note) {
            $notes[] = NoteRow::fromModel($note)->toArray();
        }

        $next = $hasMore && $last !== null ? PageCursor::encode(['created_at' => CarbonImmutable::instance($last->created_at), 'id' => $last->id]) : null;

        return new CursorPage($notes, $next);
    }

    /** @throws ValidationException a body that is empty or longer than BODY_MAX_LENGTH (the request checks first; this is the backstop) */
    public function store(Customer $customer, User $author, string $body): CustomerNote
    {
        if ($customer->trashed()) {
            throw new NotFoundHttpException('There is no such customer.');
        }

        $body = trim($body);

        if ($body === '' || mb_strlen($body) > self::BODY_MAX_LENGTH) {
            throw ValidationException::withMessages(['body' => 'متن یادداشت باید بین ۱ تا ۲۰۰۰ نویسه باشد.']);
        }

        return DB::transaction(function () use ($customer, $author, $body): CustomerNote {
            $note = CustomerNote::query()->create(['customer_id' => $customer->id, 'user_id' => $author->id, 'body' => $body]);

            $this->record($customer->id, CustomerEventType::NoteAdded, $note->id);

            return $note;
        });
    }

    /** @throws AuthorizationException when the actor is neither the note's author (holding customers.note) nor a customers.manage_notes holder */
    public function destroy(CustomerNote $note, User $actor): void
    {
        if (! $this->mayDelete($note, $actor)) {
            throw new AuthorizationException('You may not delete this note.');
        }

        DB::transaction(function () use ($note): void {
            $note->delete();

            $this->record($note->customer_id, CustomerEventType::NoteDeleted, $note->id);
        });
    }

    private function mayDelete(CustomerNote $note, User $actor): bool
    {
        return $this->permissions->allows($actor, 'customers', 'manage_notes')
            || ($note->user_id === $actor->id && $this->permissions->allows($actor, 'customers', 'note'));
    }

    /** The timeline event of a note change. The payload carries the note's id and nothing of its text. */
    private function record(int $customerId, CustomerEventType $type, int $noteId): void
    {
        CustomerEvent::query()->create([
            'customer_id' => $customerId,
            'event_type' => $type,
            'payload' => ['note_id' => $noteId],
            'happened_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}
