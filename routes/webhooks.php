<?php

declare(strict_types=1);

use App\Http\Controllers\WooWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Machine-to-machine endpoints (P2-09). Loaded from bootstrap/app.php OUTSIDE the `web` group: no session, no cookies, no
| CSRF token — a WooCommerce server cannot send any. Authenticity is the secret, the IP allowlist and the HMAC signature,
| checked by WooWebhookRequest before the controller runs.
*/
Route::post('webhooks/woo', WooWebhookController::class)->name('webhooks.woo');
