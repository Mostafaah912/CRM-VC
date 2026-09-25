<?php

/**
 * P0-00 — WooCommerce Verification Script (HeyMode Customer OS)
 *
 * Standalone. Not part of the application. Reads credentials from .env.
 * Probes the live WooCommerce API to verify the 10 assumptions in PRD §03,
 * then prints a report ready to paste into ARCHITECTURE.md.
 *
 * Run from the project root:   php verify-woo.php
 *
 * It only READS from WooCommerce. It never writes anything.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. Load credentials from .env (no framework, minimal parser)
// ---------------------------------------------------------------------------
$envPath = __DIR__.'/.env';
if (! is_file($envPath)) {
    fwrite(STDERR, "خطا: فایل .env پیدا نشد. اسکریپت را از ریشه پروژه اجرا کنید.\n");
    exit(1);
}

$env = [];
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    if (! str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    $v = trim($v);
    // strip surrounding quotes if present
    if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
        $v = substr($v, 1, -1);
    }
    $env[$k] = $v;
}

$base = rtrim($env['WOO_BASE_URL'] ?? '', '/');
$key = $env['WOO_CONSUMER_KEY'] ?? '';
$secret = $env['WOO_CONSUMER_SECRET'] ?? '';

if ($base === '' || $key === '' || $secret === '') {
    fwrite(STDERR, "خطا: WOO_BASE_URL یا WOO_CONSUMER_KEY یا WOO_CONSUMER_SECRET در .env خالی است.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 2. Tiny HTTP helper (Basic Auth over HTTPS)
// ---------------------------------------------------------------------------
/**
 * @return array{status:int, body:mixed, headers:array<string,string>}
 */
function wooGet(string $base, string $key, string $secret, string $endpoint, array $query = []): array
{
    $url = $base.'/wp-json/wc/v3/'.ltrim($endpoint, '/');
    if ($query) {
        $url .= '?'.http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $key.':'.$secret,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'HeyMode-Verify/1.0',
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);

        return ['status' => 0, 'body' => null, 'headers' => [], 'error' => $err];
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($raw, 0, $headerSize);
    $bodyStr = substr($raw, $headerSize);

    $headers = [];
    foreach (explode("\r\n", $rawHeaders) as $h) {
        if (str_contains($h, ':')) {
            [$hk, $hv] = explode(':', $h, 2);
            $headers[strtolower(trim($hk))] = trim($hv);
        }
    }

    $body = json_decode($bodyStr, true);

    return ['status' => $status, 'body' => $body, 'headers' => $headers, 'error' => null];
}

// small helpers for output
function line(string $s = ''): void
{
    echo $s."\n";
}
function h(string $s): void
{
    line();
    line('### '.$s);
}
function ok(string $s): void
{
    line('  ✓ '.$s);
}
function warn(string $s): void
{
    line('  ⚠ '.$s);
}
function info(string $s): void
{
    line('  • '.$s);
}

/**
 * P2-07: does each refund line say which ORDER ITEM it refunds (meta `_refunded_item_id`)?
 * Prints counts and meta key NAMES only — no URL, no credentials, no names, no amounts.
 *
 * @param  array<int, mixed>  $refunds  body of GET orders/{id}/refunds
 */
function reportRefundLink(array $refunds): void
{
    $lines = 0;
    $withMeta = 0;
    $withLink = 0;
    $validLink = 0;
    $metaKeys = [];

    foreach ($refunds as $refund) {
        foreach (($refund['line_items'] ?? []) as $lineItem) {
            $lines++;
            $meta = $lineItem['meta_data'] ?? null;

            if (! is_array($meta)) {
                continue;
            }

            $withMeta++;

            foreach ($meta as $entry) {
                $metaKey = (string) ($entry['key'] ?? '');
                $metaKeys[$metaKey] = true;

                if ($metaKey !== '_refunded_item_id') {
                    continue;
                }

                $withLink++;
                $value = $entry['value'] ?? null;

                if ((is_int($value) && $value > 0) || (is_string($value) && ctype_digit($value) && (int) $value > 0)) {
                    $validLink++;
                }
            }
        }
    }

    info('تعداد عودت‌ها: '.count($refunds).' | خطوط عودت: '.$lines.' | خطوط دارای meta_data: '.$withMeta);
    info('کلیدهای meta_data دیده‌شده: '.($metaKeys === [] ? '(هیچ)' : implode(', ', array_keys($metaKeys))));

    if ($lines === 0) {
        warn('عودت بدون line_items؛ پیوند به قلم اصلی قابل بررسی نیست.');
    } elseif ($validLink === $lines) {
        ok("همه‌ی {$lines} خط عودت کلید _refunded_item_id با شناسه‌ی عددی مثبت دارند → پیوند به قلم اصلی موجود است.");
    } else {
        warn("فقط {$validLink} از {$lines} خط عودت _refunded_item_id معتبر دارند ({$withLink} خط کلید را دارند).");
    }
}

// Single read-only GET: php verify-woo.php --refund-link=<woo order id that has refunds>
foreach ($argv as $argument) {
    if (! str_starts_with($argument, '--refund-link')) {
        continue;
    }

    $orderId = (int) (str_contains($argument, '=') ? substr($argument, strpos($argument, '=') + 1) : 53960);
    h("P2-07 — پیوند خط عودت به قلم سفارش (سفارش #{$orderId})");
    $refunds = wooGet($base, $key, $secret, "orders/{$orderId}/refunds");

    if ($refunds['error'] !== null) {
        warn('خطای اتصال؛ بررسی انجام نشد.');
        exit(1);
    }

    if ($refunds['status'] !== 200 || ! is_array($refunds['body'])) {
        warn('پاسخ HTTP '.$refunds['status'].' برای اندپوینت عودت؛ بررسی انجام نشد.');
        exit(1);
    }

    reportRefundLink($refunds['body']);
    exit(0);
}

// ---------------------------------------------------------------------------
// 3. Connectivity check
// ---------------------------------------------------------------------------
line('========================================================');
line(' P0-00 — گزارش راستی‌آزمایی ووکامرس هی‌مد');
line(' تاریخ اجرا: '.date('Y-m-d H:i:s'));
line(' آدرس: '.$base);
line('========================================================');

$probe = wooGet($base, $key, $secret, 'orders', ['per_page' => 1]);

if (($probe['error'] ?? null)) {
    line();
    line('خطای اتصال: '.$probe['error']);
    line('بررسی کنید: آدرس درست است؟ سایت از این دستگاه باز می‌شود؟ HTTPS معتبر است؟');
    exit(1);
}
if ($probe['status'] === 401) {
    line();
    line('خطای احراز هویت (401): Consumer Key یا Secret اشتباه است یا دسترسی ندارد.');
    exit(1);
}
if ($probe['status'] !== 200) {
    line();
    line('پاسخ غیرمنتظره: HTTP '.$probe['status']);
    line(is_string($probe['body']) ? $probe['body'] : json_encode($probe['body'], JSON_UNESCAPED_UNICODE));
    exit(1);
}

ok('اتصال و احراز هویت موفق بود.');

// ---------------------------------------------------------------------------
// Gather a working sample of recent orders (a few pages)
// ---------------------------------------------------------------------------
$sampleOrders = [];
$pagesToScan = 6;          // up to 6 pages x 100 = 600 recent orders
for ($p = 1; $p <= $pagesToScan; $p++) {
    $r = wooGet($base, $key, $secret, 'orders', [
        'per_page' => 100,
        'page' => $p,
        'status' => 'any',
        'orderby' => 'date',
        'order' => 'desc',
    ]);
    if ($r['status'] !== 200 || ! is_array($r['body']) || count($r['body']) === 0) {
        break;
    }
    $sampleOrders = array_merge($sampleOrders, $r['body']);
    if (count($r['body']) < 100) {
        break;
    }
}
$sampleCount = count($sampleOrders);

// ---------------------------------------------------------------------------
// V8 — total order count (from header on a status-filtered query)
// ---------------------------------------------------------------------------
h('V8 — حجم کل تاریخچه سفارش');
$totalProbe = wooGet($base, $key, $secret, 'orders', ['per_page' => 1, 'status' => 'any']);
$totalOrders = $totalProbe['headers']['x-wp-total'] ?? 'نامعلوم';
info("تعداد کل سفارش‌ها (هر وضعیت): {$totalOrders}");
if (is_numeric($totalOrders)) {
    if ((int) $totalOrders > 200000) {
        warn('بیش از ۲۰۰٬۰۰۰ سفارش — طبق سند، Full Sync را به ۲۴ ماه اخیر محدود کنید.');
    } else {
        ok('در محدوده قابل مدیریت برای Full Sync کامل.');
    }
}
info("نمونه بررسی‌شده در این اجرا: {$sampleCount} سفارش اخیر");

// ---------------------------------------------------------------------------
// V2 — order statuses actually in use
// ---------------------------------------------------------------------------
h('V2 — وضعیت‌های سفارش (Slug واقعی)');
$statusCounts = [];
foreach ($sampleOrders as $o) {
    $s = $o['status'] ?? 'unknown';
    $statusCounts[$s] = ($statusCounts[$s] ?? 0) + 1;
}
arsort($statusCounts);
if ($statusCounts) {
    foreach ($statusCounts as $s => $c) {
        info("وضعیت '{$s}': {$c} سفارش در نمونه");
    }
    line();
    info('در config/woo.php، realized_statuses باید شامل معادل‌های پرداخت‌شده/ارسال‌شده/تکمیل‌شده باشد.');
    info("پیش‌فرض سند: ['processing','shipped','completed'] — با لیست بالا تطبیق دهید.");
    if (! isset($statusCounts['shipped']) && ! isset($statusCounts['wc-shipped'])) {
        warn("وضعیت 'shipped' در نمونه دیده نشد. اگر وضعیت ارسال سفارشی دارید، Slug واقعی‌اش را پیدا و ثبت کنید.");
    }
} else {
    warn('هیچ سفارشی در نمونه نبود.');
}

// ---------------------------------------------------------------------------
// V1 — currency (Toman vs Rial) + decimals
// ---------------------------------------------------------------------------
h('V1 — واحد پول و اعشار');
$settingsGeneral = wooGet($base, $key, $secret, 'settings/general');
$currencyCode = 'نامعلوم';
$decimals = 'نامعلوم';
if ($settingsGeneral['status'] === 200 && is_array($settingsGeneral['body'])) {
    foreach ($settingsGeneral['body'] as $opt) {
        if (($opt['id'] ?? '') === 'woocommerce_currency') {
            $currencyCode = $opt['value'] ?? 'نامعلوم';
        }
        if (($opt['id'] ?? '') === 'woocommerce_price_num_decimals') {
            $decimals = $opt['value'] ?? 'نامعلوم';
        }
    }
}
info("کد واحد پول ووکامرس: {$currencyCode}");
info("تعداد رقم اعشار قیمت: {$decimals}");

// look at a real order total to sanity-check magnitude
$exampleTotal = null;
foreach ($sampleOrders as $o) {
    if (isset($o['total']) && (float) $o['total'] > 0) {
        $exampleTotal = $o['total'];
        break;
    }
}
if ($exampleTotal !== null) {
    info("نمونه مبلغ یک سفارش واقعی: {$exampleTotal}");
    warn('این عدد را با مبلغی که در پنل همان سفارش می‌بینید مقایسه کنید:');
    warn('اگر یکی بود → واحد ذخیره تومان است. اگر عدد API ده برابر بود → ریال است (ضریب در config/hm.php).');
}
if ($decimals !== 'نامعلوم' && $decimals !== '0') {
    warn("اعشار غیرصفر است ({$decimals}). سند فرض «بدون اعشار» دارد — بررسی کنید مبالغ واقعاً صحیح‌اند.");
}

// ---------------------------------------------------------------------------
// V3 — guest checkout + V4 — phone presence
// ---------------------------------------------------------------------------
h('V3 و V4 — خرید مهمان و وجود موبایل');
$guest = 0;
$registered = 0;
$noPhone = 0;
$withPhone = 0;
foreach ($sampleOrders as $o) {
    $cid = (int) ($o['customer_id'] ?? 0);
    if ($cid === 0) {
        $guest++;
    } else {
        $registered++;
    }

    $phone = trim((string) ($o['billing']['phone'] ?? ''));
    if ($phone === '') {
        $noPhone++;
    } else {
        $withPhone++;
    }
}
$total = max(1, $sampleCount);
$guestPct = round($guest / $total * 100, 1);
$noPhonePct = round($noPhone / $total * 100, 1);

info("سفارش مهمان (customer_id=0): {$guest} ({$guestPct}٪ نمونه)");
info("سفارش کاربر ثبت‌نامی: {$registered}");
if ($guest > 0) {
    ok('خرید مهمان فعال است — مسیر woo_guest_order در Identity استفاده می‌شود.');
} else {
    info('در نمونه، خرید مهمان دیده نشد.');
}
line();
info("سفارش بدون موبایل: {$noPhone} ({$noPhonePct}٪ نمونه)");
if ($noPhonePct > 1.0) {
    warn('*** دروازه V4: بیش از ۱٪ سفارش‌ها موبایل خالی دارند. ***');
    warn('طبق سند، اگر این عدد بالای ۱٪ باشد مدل هویت به مسیر Fallback با ایمیل نیاز دارد.');
    warn('این تصمیم باید قبل از Sprint 2 گرفته شود.');
} else {
    ok('کمتر از ۱٪ سفارش بدون موبایل — کلید هویت موبایل امن است.');
}

// ---------------------------------------------------------------------------
// V5 — one phone / multiple names ; V10 — email reliability
// ---------------------------------------------------------------------------
h('V5 و V10 — موبایل مشترک و اتکاپذیری ایمیل');
$phoneNames = [];   // normalized phone => set of lastnames
$emails = [];
$emailValid = 0;
foreach ($sampleOrders as $o) {
    $phone = preg_replace('/\D+/', '', (string) ($o['billing']['phone'] ?? ''));
    $last = trim((string) ($o['billing']['last_name'] ?? ''));
    if ($phone !== '') {
        $phoneNames[$phone] = $phoneNames[$phone] ?? [];
        if ($last !== '') {
            $phoneNames[$phone][$last] = true;
        }
    }
    $email = trim((string) ($o['billing']['email'] ?? ''));
    if ($email !== '') {
        $emails[$email] = ($emails[$email] ?? 0) + 1;
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emailValid++;
        }
    }
}
$sharedPhones = 0;
foreach ($phoneNames as $names) {
    if (count($names) > 1) {
        $sharedPhones++;
    }
}
info("موبایل‌هایی که با بیش از یک نام خانوادگی سفارش داده‌اند: {$sharedPhones}");
if ($sharedPhones > 0) {
    warn('این‌ها در identity_conflicts برای بازبینی ثبت می‌شوند (مشتری تقسیم نمی‌شود).');
} else {
    ok('موبایل مشترک با نام‌های متفاوت در نمونه دیده نشد.');
}
line();
$emailValidPct = $withPhone > 0 ? round($emailValid / $total * 100, 1) : 0;
info("سفارش‌های با ایمیل معتبر: {$emailValid} ({$emailValidPct}٪ نمونه)");
if ($emailValidPct >= 90) {
    info('ایمیل نسبتاً کامل است — می‌تواند شناسه ثانویه شود (اختیاری).');
} else {
    info('ایمیل ناقص است — طبق سند فقط شناسه ثانویه و بدون تطبیق در فاز ۱.');
}

// ---------------------------------------------------------------------------
// V6 & V7 — refund structure
// ---------------------------------------------------------------------------
h('V6 و V7 — ساختار عودت');
$orderWithRefund = null;
foreach ($sampleOrders as $o) {
    if (! empty($o['refunds']) && is_array($o['refunds'])) {
        $orderWithRefund = $o;
        break;
    }
}
if ($orderWithRefund === null) {
    info('در نمونه اخیر، سفارشی با عودت پیدا نشد. با داده بیشتر دوباره بررسی می‌شود.');
} else {
    $oid = $orderWithRefund['id'];
    info("سفارش نمونه با عودت: #{$oid}");
    $ref = wooGet($base, $key, $secret, "orders/{$oid}/refunds");
    if ($ref['status'] === 200 && is_array($ref['body']) && count($ref['body']) > 0) {
        $first = $ref['body'][0];
        $hasLineItems = ! empty($first['line_items']);
        info('مبلغ عودت: '.($first['amount'] ?? 'نامعلوم'));
        if ($hasLineItems) {
            ok('عودت دارای line_items است → عودت در سطح قلم قابل محاسبه است (طبق فرض سند).');
            reportRefundLink($ref['body']);
        } else {
            warn('عودت فقط مبلغ کل دارد، بدون line_items → عودت سطح قلم محاسبه نمی‌شود؛ ستون‌ها خالی می‌مانند.');
        }
    } else {
        info('اندپوینت refunds برای این سفارش داده‌ای برنگرداند.');
    }
}
info('V7: وب‌هوک عودت به‌صورت پیش‌فرض فرض نمی‌شود؛ Polling ساعتی استفاده می‌شود.');

// ---------------------------------------------------------------------------
// V9 — SKU coverage on variations
// ---------------------------------------------------------------------------
h('V9 — پوشش SKU روی محصولات و واریانت‌ها');
$prod = wooGet($base, $key, $secret, 'products', ['per_page' => 100, 'status' => 'publish']);
$noSku = 0;
$withSku = 0;
$variableProducts = [];
if ($prod['status'] === 200 && is_array($prod['body'])) {
    foreach ($prod['body'] as $pr) {
        $sku = trim((string) ($pr['sku'] ?? ''));
        if ($sku === '') {
            $noSku++;
        } else {
            $withSku++;
        }
        if (($pr['type'] ?? '') === 'variable') {
            $variableProducts[] = $pr['id'];
        }
    }
    $pt = max(1, $withSku + $noSku);
    info("نمونه محصول بررسی‌شده: {$pt}");
    info("محصول بدون SKU: {$noSku}");
    if ($noSku > 0) {
        warn('بعضی محصولات SKU ندارند. Resolve محصول به variation_id تکیه می‌کند (طبق سند).');
    } else {
        ok('همه محصولات نمونه SKU دارند.');
    }
    // check a couple of variable products' variations
    $checked = 0;
    $varNoSku = 0;
    $varTotal = 0;
    foreach ($variableProducts as $vpid) {
        if ($checked >= 3) {
            break;
        }
        $vr = wooGet($base, $key, $secret, "products/{$vpid}/variations", ['per_page' => 100]);
        if ($vr['status'] === 200 && is_array($vr['body'])) {
            foreach ($vr['body'] as $v) {
                $varTotal++;
                if (trim((string) ($v['sku'] ?? '')) === '') {
                    $varNoSku++;
                }
            }
        }
        $checked++;
    }
    if ($varTotal > 0) {
        info("واریانت بررسی‌شده (از {$checked} محصول متغیر): {$varTotal}، بدون SKU: {$varNoSku}");
        if ($varNoSku > 0) {
            warn('بعضی واریانت‌ها SKU ندارند — در سند SKU روی واریانت nullable در نظر گرفته شده.');
        } else {
            ok('همه واریانت‌های بررسی‌شده SKU دارند.');
        }
    }
} else {
    warn("خواندن محصولات ناموفق بود (HTTP {$prod['status']}).");
}

// ---------------------------------------------------------------------------
// Final note
// ---------------------------------------------------------------------------
line();
line('========================================================');
line(' پایان گزارش.');
line(' این خروجی را در بخش «یافته‌های راستی‌آزمایی (P0-00)» فایل ARCHITECTURE.md کپی کنید.');
line(' هر جا ⚠ دیدید، قبل از Sprint 2 تصمیم لازم را بگیرید.');
line('========================================================');
