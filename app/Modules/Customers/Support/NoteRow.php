<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Modules\Customers\Models\CustomerNote;
use App\Support\TehranDateTime;
use Carbon\CarbonImmutable;

/**
 * One note as the notes list and the store response show it. Built from named fields only: the author is an id (so the page can
 * tell "mine") and a display name, never an email; the customer id is not sent.
 *
 * @phpstan-type NoteShape array{id: int, author_id: int, author_name: string|null, body: string, created_at_jalali: string, created_at_iso: string}
 */
final readonly class NoteRow
{
    /** The columns of customer_notes a list must select for a row to be built (the author is loaded as id + name only). */
    public const COLUMNS = ['id', 'customer_id', 'user_id', 'body', 'created_at'];

    public static function fromModel(CustomerNote $note): self
    {
        return new self($note);
    }

    private function __construct(private CustomerNote $note) {}

    /** @return NoteShape */
    public function toArray(): array
    {
        $n = $this->note;
        $at = CarbonImmutable::instance($n->created_at);

        return [
            'id' => $n->id,
            'author_id' => $n->user_id,
            'author_name' => $n->author?->name,
            'body' => $n->body,
            'created_at_jalali' => TehranDateTime::format($at),
            'created_at_iso' => $at->utc()->toIso8601ZuluString(),
        ];
    }
}
