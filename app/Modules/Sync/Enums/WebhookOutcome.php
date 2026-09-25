<?php

declare(strict_types=1);

namespace App\Modules\Sync\Enums;

/** What became of an authentic delivery. Always answered 200: Woo retries, and eventually disables the webhook, on anything else. */
enum WebhookOutcome: string
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
    case Ignored = 'ignored';
}
