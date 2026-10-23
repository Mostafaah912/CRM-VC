<?php

declare(strict_types=1);

namespace App\Modules\Sync\Enums;

/** Why a webhook delivery is, or is not, authentic. Only the log sees the reason; the HTTP answer is the same 401 for all of them. */
enum WebhookVerdict: string
{
    case Authentic = 'authentic';
    /** P8-03: `woo.webhook_enabled` is false (staging by default): every delivery is refused before any other check. */
    case Disabled = 'disabled';
    case SecretMissing = 'secret_missing';
    case IpDenied = 'ip_denied';
    case SignatureInvalid = 'signature_invalid';
}
