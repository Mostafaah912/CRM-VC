<?php

declare(strict_types=1);

namespace App\Modules\Sync\Enums;

/** Why a webhook delivery is, or is not, authentic. Only the log sees the reason; the HTTP answer is the same 401 for all of them. */
enum WebhookVerdict: string
{
    case Authentic = 'authentic';
    case SecretMissing = 'secret_missing';
    case IpDenied = 'ip_denied';
    case SignatureInvalid = 'signature_invalid';
}
