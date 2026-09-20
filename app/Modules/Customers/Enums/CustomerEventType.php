<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

enum CustomerEventType: string
{
    case OrderPlaced = 'order_placed';
    case OrderRefunded = 'order_refunded';
    case NoteAdded = 'note_added';
    case NoteDeleted = 'note_deleted';
    case StatusChanged = 'status_changed';
}
