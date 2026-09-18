# HeyMode Customer OS — Architecture Log

این فایل حافظه‌ی مشترک پروژه است. با هر تصمیم معماری و هر یافته‌ی راستی‌آزمایی به‌روز می‌شود.

## محیط توسعه (Local Mac) — ثبت‌شده در شروع پروژه

- سیستم: MacBook Pro (MacBookPro12,1) / Intel x86_64 / macOS 12.7.6
- PHP: 8.4 (از طریق Laravel Herd)
- Laravel: نصب‌شده با starter kit — React + Inertia + Laravel built-in auth (با 2FA و Passkeys)
- Node.js: 22.17.0 / npm: 10.9.2
- Composer: نصب‌شده
- PostgreSQL: 15.19 ← توجه: نسخه ۱۵ است، نه ۱۶
- Redis: 7.4.6
- تست: Pest
- Homebrew: نصب‌شده ولی به‌عنوان وابستگی پروژه استفاده نمی‌شود (محدودیت Intel + macOS 12)

## تصمیم مهم درباره نسخه PostgreSQL

- محیط توسعه روی PostgreSQL 15 است. **روی VPS هم باید PostgreSQL 15 نصب شود**، نه 16،
  تا اختلاف نسخه بین توسعه و تولید نباشد.
- تمام SQLهای سند (NTILE، percentile_cont، JSONB، generate_series، window functions)
  روی نسخه 15 پشتیبانی می‌شوند.

## استقرار

- فعلاً VPS استفاده نمی‌شود. کل توسعه، تست و دیباگ روی مک محلی انجام می‌شود.
- استقرار روی VPS لینوکس در Sprint 8 انجام می‌شود.
- Supervisor فقط بخشی از استقرار VPS است، نه محیط توسعه. روی مک، Queue Worker
  به‌صورت دستی با `php artisan horizon` اجرا می‌شود.

## یافته‌های راستی‌آزمایی (P0-00)

### V8 — حجم کل تاریخچه سفارش

• تعداد کل سفارش‌ها (هر وضعیت): 24421
✓ در محدوده قابل مدیریت برای Full Sync کامل.
• نمونه بررسی‌شده در این اجرا: 600 سفارش اخیر

### V2 — وضعیت‌های سفارش (Slug واقعی)

• وضعیت 'completed': 296 سفارش در نمونه
• وضعیت 'cancelled': 239 سفارش در نمونه
• وضعیت 'processing': 59 سفارش در نمونه
• وضعیت 'pending': 3 سفارش در نمونه
• وضعیت 'refunded': 3 سفارش در نمونه

• در config/woo.php، realized_statuses باید شامل معادل‌های پرداخت‌شده/ارسال‌شده/تکمیل‌شده باشد.
• پیش‌فرض سند: ['processing','shipped','completed'] — با لیست بالا تطبیق دهید.
⚠ وضعیت 'shipped' در نمونه دیده نشد. اگر وضعیت ارسال سفارشی دارید، Slug واقعی‌اش را پیدا و ثبت کنید.

### V1 — واحد پول و اعشار

• کد واحد پول ووکامرس: IRT
• تعداد رقم اعشار قیمت: 0
• نمونه مبلغ یک سفارش واقعی: 403880
⚠ این عدد را با مبلغی که در پنل همان سفارش می‌بینید مقایسه کنید:
⚠ اگر یکی بود → واحد ذخیره تومان است. اگر عدد API ده برابر بود → ریال است (ضریب در config/hm.php).

### V3 و V4 — خرید مهمان و وجود موبایل

• سفارش مهمان (customer_id=0): 0 (0٪ نمونه)
• سفارش کاربر ثبت‌نامی: 600
• در نمونه، خرید مهمان دیده نشد.

• سفارش بدون موبایل: 0 (0٪ نمونه)
✓ کمتر از ۱٪ سفارش بدون موبایل — کلید هویت موبایل امن است.

### V5 و V10 — موبایل مشترک و اتکاپذیری ایمیل

• موبایل‌هایی که با بیش از یک نام خانوادگی سفارش داده‌اند: 1
⚠ این‌ها در identity_conflicts برای بازبینی ثبت می‌شوند (مشتری تقسیم نمی‌شود).

• سفارش‌های با ایمیل معتبر: 2 (0.3٪ نمونه)
• ایمیل ناقص است — طبق سند فقط شناسه ثانویه و بدون تطبیق در فاز ۱.

### V6 و V7 — ساختار عودت

• سفارش نمونه با عودت: #53960
• مبلغ عودت: 11340000
✓ عودت دارای line_items است → عودت در سطح قلم قابل محاسبه است (طبق فرض سند).
• V7: وب‌هوک عودت به‌صورت پیش‌فرض فرض نمی‌شود؛ Polling ساعتی استفاده می‌شود.

### V9 — پوشش SKU روی محصولات و واریانت‌ها

• نمونه محصول بررسی‌شده: 100
• محصول بدون SKU: 0
✓ همه محصولات نمونه SKU دارند.
• واریانت بررسی‌شده (از 3 محصول متغیر): 15، بدون SKU: 0
✓ همه واریانت‌های بررسی‌شده SKU دارند.

## تصمیم‌های قطعی بعد از P0-00

- **واحد پول: تومان (IRT).** مبالغ به تومان و بدون اعشار ذخیره می‌شوند (bigint).
  هیچ تبدیلی لازم نیست — API هم تومان می‌دهد. (تأییدشده با مبلغ نمونه ۴۰۳٬۸۸۰.)
- **realized_statuses = ['processing','completed']** (وضعیت shipped وجود ندارد).
- **خرید مهمان: غیرفعال.** همه سفارش‌ها customer_id دارند. مسیر woo_guest_order بلااستفاده.
- **موبایل: ۱۰۰٪ موجود.** کلید هویت موبایل بدون مسیر Fallback.
- **عودت: دارای line_items.** عودت سطح قلم پیاده می‌شود. (نمونه: عودت ۱٬۱۳۴٬۰۰۰ تومان)
- **ایمیل: تقریباً وجود ندارد (۰٫۳٪).** حتی به‌عنوان شناسه ثانویه هم استفاده نمی‌شود.
- **کل سفارش‌ها: ~۲۴٬۴۲۱.** Full Sync کامل بدون محدودیت زمانی.

## P0-01 — Scaffold RTL + shadcn/ui (نسخه‌های واقعی نصب‌شده)

اسکلت Laravel با `laravel new` از قبل با استارتر React + Inertia + Fortify (2FA و Passkeys) و shadcn/ui (استایل `new-york`، Tailwind v4 CSS-first) ساخته شده بود. در این گام دوباره Scaffold نشد؛ فقط RTL اضافه و نسخه‌های واقعی ثبت شد.

- Laravel Framework: **13.32.0**
- PHP: **8.4.15** (از طریق Herd)
- Inertia (inertiajs/inertia-laravel): **3.3.4**
- Laravel Fortify: **1.39.0**
- Pest: **5.2.1**
- React / React DOM: **19.3.0**
- @inertiajs/react: **3.7.1**
- TypeScript: **5.9.3**
- Tailwind CSS: **4.3.3** (بدون `tailwind.config.js` — پیکربندی CSS-first در `resources/css/app.css`)
- Vite: **8.3.0**
- Node.js: **22.17.0** / npm: **10.9.2**
- shadcn/ui: از قبل نصب‌شده (`components.json`، استایل `new-york`، `baseColor: neutral`، آیکون‌ها از `lucide-react`) — تأیید شد که `npm run build` سبز است.

### تغییرات RTL

- طبق تصمیم D10 (فقط فارسی، بدون i18n)، `resources/views/app.blade.php` اکنون همیشه `lang="fa" dir="rtl"` دارد — دیگر وابسته به `app()->getLocale()` نیست، چون این اپ زبان دوم ندارد.
- Tailwind v4 از `rtl:`/`ltr:` بر پایه ویژگی `dir` به‌صورت داخلی پشتیبانی می‌کند؛ نیازی به پلاگین یا `tailwind.config.js` جداگانه نبود.
- `APP_LOCALE` در `.env` عمداً روی `en` باقی ماند — طبق D10 متن رابط کاربری در خود کامپوننت React فارسی نوشته می‌شود، نه از طریق فایل‌های ترجمه Laravel؛ تغییر `APP_LOCALE` بدون فایل‌های `resources/lang/fa` پیام‌های اعتبارسنجی Fortify را می‌شکند و در Scope این گام نیست.
- ⚠ **ریسک ثبت‌شده برای Sprint 3:** کامپوننت‌های shadcn/ui استارتر (sidebar، dropdown، breadcrumb و…) از `ml-`/`mr-`/`left-`/`right-` فیزیکی استفاده می‌کنند که با `dir="rtl"` خودکار flip نمی‌شوند. اصلاح آن‌ها به کلاس‌های منطقی (`ms-`/`me-`/`start-`/`end-`) هنگام ساخت UI واقعی در Sprint 3 انجام می‌شود، نه اینجا.

## P0-02 — Horizon + تأیید Queue روی Redis

- `laravel/horizon` نصب شد (نسخه منتشرشده با `composer.lock`: **^5.49**، از طریق `composer require laravel/horizon` + `php artisan horizon:install`).
- `app/Providers/HorizonServiceProvider.php` ثبت شد در `bootstrap/providers.php`. Gate پیش‌فرض `viewHorizon` دست‌نخورده ماند (فقط env محلی مجاز است)؛ محدودسازی واقعی به نقش Owner زمانی انجام می‌شود که ماژول Core/Permissions (P0-07) و کاربران واقعی (Sprint 8) وجود داشته باشند — طبق چک‌لیست سخت‌سازی بخش ۲۱، نه اینجا.
- `config/horizon.php`: صف `supervisor-1` روی ترتیب اولویت بخش ۲۲ تنظیم شد: `['critical','sync','metrics','ai','default']`. تعریف Job واقعی برای هرکدام در Sprintهای بعدی (Sync/Metrics/AI) اضافه می‌شود.
- **اصلاح باگ در `.env`:** `DB_CONNECTION` دو بار تعریف شده بود (`sqlite` سپس `pgsql`) — خط تکراری حذف شد (مقدار مؤثر همیشه `pgsql` بود، چون phpdotenv خط دوم را می‌گیرد، ولی تکرار گمراه‌کننده بود).
- **`QUEUE_CONNECTION` و `CACHE_STORE` از `database` به `redis` تغییر کردند** — طبق معماری بخش ۰۶ («Redis: queue + cache + lock»). `.env.example` هم با همین مقادیر و با `DB_CONNECTION=pgsql` به‌روز شد تا نمونه واقعی پروژه را نشان دهد، نه پیش‌فرض SQLite اسکلت Laravel.
- **یافته محیطی مهم:** روی این مک، Redis 7.4.6 نصب است (`/usr/local/bin/redis-server`) ولی به‌صورت سرویس خودکار (launchd/brew services) اجرا نمی‌شود — باید دستی با `redis-server --daemonize yes` بالا بیاید. این باید در مستندات Setup پروژه (بعداً) یا اسکریپت dev ثبت شود؛ فعلاً فقط اینجا یادداشت شد.
- **تأیید سرتاسری صف:** یک Job موقت (`HorizonProbeJob`, بعد از تست حذف شد) روی صف پیش‌فرض dispatch شد، `php artisan horizon` آن را از Redis برداشت، اجرا کرد و نتیجه در Cache (که آن هم الان روی Redis است) قابل مشاهده بود. `horizon:status` → `running`، `horizon:terminate` تمیز خاتمه داد.
- PostgreSQL 15.19 هم تأیید شد در دسترس است (`php artisan db:show`).

## اصلاح زیرساخت تست — قبل از P0-03

`phpunit.xml` استارتر روی `DB_CONNECTION=sqlite` و `DB_DATABASE=:memory:` تنظیم شده بود که مستقیماً قانون بخش ۳/۸ CLAUDE.md («هرگز SQLite») را نقض می‌کرد. برای اینکه اولین تست‌های TEST-FIRST (P0-03) درست شروع شوند:

- دیتابیس جدا `heymode_testing` روی همان PostgreSQL محلی ساخته شد (با اجازه کاربر، چون نقش `heymode` نیاز به `CREATEDB` داشت که ابتدا نداشت).
- اتصال/رمز عبور تست در `.env.testing` (در `.gitignore`، هرگز Commit نمی‌شود) قرار گرفت؛ `phpunit.xml` دیگر `DB_CONNECTION`/`DB_DATABASE`/`DB_URL` را Override نمی‌کند تا مقادیر واقعی از `.env.testing` خوانده شوند.
- نتیجه: `RefreshDatabase` هر بار `heymode_testing` را Migrate می‌کند، نه دیتابیس توسعه `heymode` را — داده محلی دست‌نخورده می‌ماند.

## P0-03 — PhoneNormalizer، JalaliDate، Money (TEST FIRST)

- `App\Support\PhoneNormalizer::normalize()` طبق بخش ۰۸ PRD: علامت‌های RTL/LTR (U+200E/U+200F) حذف، ارقام فارسی و عربی-هندی به ASCII، سپس تشخیص فرمت (`0912...`، `912...`، `+98912...`، `0098912...`، با فاصله/خط‌تیره) و تبدیل به خروجی قطعی `989XXXXXXXXX`. برای خالی/کوتاه/غیرموبایل (شهری مثل `021`/`031`)/غیرایرانی، `InvalidPhoneException` می‌اندازد — هرگز مقدار نامعتبر برنمی‌گرداند.
- `App\Support\JalaliDate` تبدیل شمسی↔میلادی با الگوریتم استاندارد چرخه ۳۳ ساله (همان الگوریتمی که در P0-04 معادل PL/pgSQL آن ساخته می‌شود تا دو طرف Stack هم‌رأی باشند). ورودی همیشه ابتدا به `Asia/Tehran` تبدیل می‌شود، طبق قانون «ذخیره UTC، نمایش Asia/Tehran + شمسی».
- `App\Support\Money` فرمت‌کننده/Parser مبلغ `int` تومان: نمایش با جداکننده هزارگان فارسی و پسوند «تومان»، و `parseToman()` که هر ورودی غیر عدد صحیح خالص (مثلاً با اعشار) را رد می‌کند تا «هرگز float برای پول» در همان مرز ورودی اعمال شود.
- **تست قبل از کد:** هر سه فایل تست (`tests/Unit/Support/{PhoneNormalizerTest,JalaliDateTest,MoneyTest}.php`) قبل از پیاده‌سازی نوشته و اجرا شدند (قرمز با خطای «Class not found»)، سپس پیاده‌سازی تا سبز شدن ادامه یافت.
- تست‌های JalaliDate شامل تاریخ‌های Nowruz شناخته‌شده (۱۳۹۹ تا ۱۴۰۳)، روز کبیسه ۳۰ اسفند ۱۳۹۹، و Round-trip روی بازه وسیع تاریخ میلادی — همگی سبز، که هم الگوریتم و هم لنگرهای تاریخی حافظه‌محور را تأیید متقابل می‌کنند.

## P0-04 — توابع PL/pgSQL جلالی

Migration جدید: `database/migrations/2026_09_18_103627_create_extensions_and_jalali_functions.php` (معادل «001 extensions + jalali functions» بخش ۰۹ PRD؛ اسم فایل از قرارداد Timestamp خود Laravel پیروی می‌کند، نه شماره‌گذاری متنی PRD).

- `CREATE EXTENSION IF NOT EXISTS pg_trgm` — برای GIN Index نام مشتری/محصول در Sprint 1.
- `to_jalali(timestamptz) RETURNS text` — همان الگوریتم چرخه ۳۳ ساله `App\Support\JalaliDate` (P0-03) ولی در PL/pgSQL، تا SQL طرف Metrics/Cohort (Sprint 4-6) با PHP هم‌رأی باشد. ورودی ابتدا به `Asia/Tehran` تبدیل می‌شود (طبق قانون ذخیره UTC/نمایش Tehran). `STABLE` علامت‌گذاری شد نه `IMMUTABLE`، چون تبدیل با نام Zone به‌صورت رسمی در PostgreSQL «Immutable» تضمین‌شده نیست.
- `to_jalali_month(timestamptz) RETURNS text` — همان مقدار را به ۷ کاراکتر `YYYY-MM` کوتاه می‌کند؛ دقیقاً فرمت ستون `cohort_month varchar(7)`.
- `jalali_month_diff(text, text) RETURNS int` — اختلاف ماه بین دو رشته `YYYY-MM` (`IMMUTABLE`، چون فقط محاسبه رشته‌ای است، وابسته به Timezone نیست).
- **تست اول:** `tests/Integration/JalaliFunctionsTest.php` (۱۷ تست) قبل از اجرای Migration نوشته شد و روی PostgreSQL واقعی (`heymode_testing`، از طریق `RefreshDatabase`) اجرا می‌شود؛ به همین دلیل به `tests/Integration` نیاز بود که به `phpunit.xml` و `tests/Pest.php` (خط `->in('Feature', 'Integration')`) اضافه شد.
- **یافته حین تست:** یک فرض غلط در تست اولیه («۳۰ اسفند ۱۴۰۲ وجود دارد») رد شد — با شمارش مستقیم روز بین دو لنگر Nowruz تأییدشده (۱۴۰۲-۰۱-۰۱ = ۲۰۲۳-۰۳-۲۱ و ۱۴۰۳-۰۱-۰۱ = ۲۰۲۴-۰۳-۲۰، دقیقاً ۳۶۵ روز فاصله) ثابت شد سال ۱۴۰۲ عادی (۳۶۵ روزه) است و اسفندش ۲۹ روز دارد؛ تست اصلاح شد، نه تابع.
- یک تست دیگر مقادیر تولیدشده توسط SQL را مستقیماً با خروجی زمان‌اجرای `App\Support\JalaliDate::format()` روی چند Timestamp نمونه مقایسه می‌کند تا توافق دو طرف Stack تضمین شود.
- **Idempotent/Rollback:** `up()` از `CREATE EXTENSION IF NOT EXISTS` و `CREATE OR REPLACE FUNCTION` استفاده می‌کند (اجرای دوباره خطا نمی‌دهد). `down()` هر سه تابع و پسوند را با `IF EXISTS` حذف می‌کند. رفتار با `migrate` → `migrate:rollback --step=1` → `migrate` دوباره روی دیتابیس توسعه دستی تأیید شد.
- **اصلاح جانبی:** یک خطای از پیش موجود PHPStan در `config/horizon.php` (از P0-02، `Str::slug()` با نوع `bool|string` از `env()`) هنگام اجرای Larastan روی کل پروژه کشف و با `(string)` Cast درست‌شده — چون Larastan اکنون برای اولین‌بار روی کل مسیر `app/` اجرا شد.

## P0-05 — Core Migrations

جدول‌های Core بخش ۰۹ PRD ساخته شدند: `roles`، `permissions`، `role_user`، `permission_role`، `permission_overrides`، `settings`، `audit_logs`. `users` هم با یک Migration جدید تکمیل شد (`is_active`، `last_login_at`، و کوتاه‌سازی طول `name`/`email` به ۱۲۰/۱۶۰ طبق Schema). `email_verified_at` و `two_factor_recovery_codes` عمداً حذف نشدند — فهرست جدول PRD جامع همه ستون‌های موردنیاز Framework نیست و این دو برای تأیید ایمیل و بازیابی 2FA در P0-06 لازم‌اند.

تمام Foreign Key‌ها صریح‌اند با رفتار `onDelete` مشخص: پیوندهای Pivot (`role_user`, `permission_role`) و خود `permission_overrides.user_id/permission_id` با حذف والد Cascade می‌شوند؛ ارجاع‌های «چه کسی این را ساخت/آخرین‌بار تغییر داد» (`permission_overrides.created_by`, `settings.updated_by`, `audit_logs.user_id`) با حذف کاربر `SET NULL` می‌شوند تا رکورد تاریخی از بین نرود.

CHECK Constraintها (چون Laravel فعلاً متد Fluent برای CHECK ندارد، با `DB::statement` بعد از `Schema::create` اضافه شدند):

- `permission_overrides.effect IN ('allow','deny')`
- `audit_logs.actor_type IN ('user','system','ai')` (پیش‌فرض `'user'`)

### 🔴 یافته بحرانی: Timezone پیش‌فرض اتصال PostgreSQL

هنگام تست، `password can be reset with valid token` (یک تست از پیش موجود در استارتر) شکست خورد. علت: `SHOW timezone` روی این اتصال `Asia/Tehran` برمی‌گرداند (تنظیم پیش‌فرض سرور محلی)، نه UTC. Laravel رشته‌های Datetime بدون Offset («Naive») می‌نویسد و فرض می‌کند UTC هستند؛ PostgreSQL چنین رشته‌ای را وقتی وارد ستون `timestamptz` می‌شود با Timezone **نشست** (نه UTC) تفسیر می‌کند. نتیجه: هر مقدار `timestamptz` نوشته‌شده توسط اپ ۳ ساعت و ۳۰ دقیقه جابه‌جا می‌شد — یک باگ بی‌صدا که مستقیماً قانون «Timestamps ذخیره UTC» بخش ۲ CLAUDE.md را نقض می‌کرد و روی _همه_ جدول‌های آینده (Orders، Metrics، …) اثر می‌گذاشت، نه فقط جدول‌های این Migration.

**اصلاح:** `'timezone' => 'UTC'` به اتصال `pgsql` در `config/database.php` اضافه شد (Laravel's `PostgresConnector` این کلید را می‌خواند و پس از اتصال `SET timezone` اجرا می‌کند). این مقدار Hardcode است، نه از `.env` — چون یک قاعده معماری ثابت است، نه یک تنظیم محیطی. بعد از این اصلاح، `SHOW timezone` مقدار `UTC` می‌دهد و کل Suite (۱۰۷ تست) سبز شد. توابع `to_jalali*` بخش P0-04 تحت تأثیر این باگ نبودند چون صریحاً `AT TIME ZONE 'Asia/Tehran'` را با نام منطقه می‌نویسند، نه با تکیه بر Timezone نشست.

### تبدیل ستون‌های Timestamp موجود به `timestamptz`

با کشف این مسئله، کل Schema برای ستون‌های `timestamp without time zone` باقی‌مانده از Migrationهای منتشرشده استارتر بررسی شد:

- `users` (`created_at`, `updated_at`, `email_verified_at`, `two_factor_confirmed_at`) و `password_reset_tokens.created_at` و `failed_jobs.failed_at` — با `ALTER COLUMN ... TYPE timestamptz USING ... AT TIME ZONE 'UTC'` تبدیل شدند (Migration جدید، نه ویرایش Migration منتشرشده، طبق قانون بخش ۳).
- `jobs`/`job_batches` عمداً دست‌نخورده ماندند — ستون‌های شبه‌Timestamp آن‌ها عدد صحیح Unix Timestamp داخلی Laravel Queue هستند، نه واقعاً `timestamp`؛ تبدیل آن‌ها نوع‌شان را می‌شکند و ربطی به این قانون ندارد.
- `passkeys` (`created_at`, `updated_at`, `last_used_at`) عمداً برای **P0-06** نگه داشته شد — چون Passkey بخشی از Authentication است، نه Core.

### تست Schema/Constraint

`tests/Integration/CoreSchemaTest.php` (۱۱ تست): مقادیر پیش‌فرض `users`، عدم‌وجود هیچ ستون `timestamp without time zone` باقیمانده (به‌جز سه استثنای آگاهانه بالا)، رد شدن مقدار نامعتبر `effect`/`actor_type`، یکتایی `roles.name`/`permissions(module,action)`/`permission_overrides(user_id,permission_id)`، Cascade حذف Role روی Pivotها، و رفتار PK بودن `settings.key`.

### تأیید Idempotent/Rollback

`migrate:fresh` → `migrate:rollback --step=9` → `migrate` روی دیتابیس توسعه بدون خطا اجرا شد؛ همه Migrationهای جدید `down()` کامل دارند.

## P0-06 — Authentication + 2FA

استارتر Laravel از قبل با Fortify کامل بود (Login/Logout/Register/Reset Password/Email Verification/2FA/Passkeys، همراه با Throttle روی Login و روی 2FA). طبق دستور صریح («Stack موجود را حفظ کن، Framework جدید اضافه نکن») چیزی جایگزین نشد؛ کار این گام تکمیل شکاف‌ها بود، نه بازسازی.

### باگ واقعی پیدا و رفع‌شده در Factory تست

هنگام نوشتن تست «کد 2FA نامعتبر»، `UserFactory::withTwoFactor()` معلوم شد `two_factor_secret` را با `encrypt('secret')` می‌ساخت — یعنی مقدار رمزگشایی‌شده لفظاً کلمه «secret» (۶ کاراکتر) است، در حالی که Google2FA حداقل ۱۶ کاراکتر Base32 لازم دارد و به‌جای برگرداندن «کد اشتباه»، Exception پرتاب می‌کند (`SecretKeyTooShortException`). چون تا امروز هیچ تستی واقعاً کد را Verify نمی‌کرد، این باگ بی‌صدا مانده بود. اصلاح شد به تولید یک Secret واقعی با `Google2FA::generateSecretKey()` (`database/factories/UserFactory.php`).

### تست‌های اضافه‌شده

`tests/Feature/Auth/TwoFactorChallengeTest.php`: کد ۲FA نامعتبر رد می‌شود و کاربر را وارد نمی‌کند؛ کد بازیابی نامعتبر هم همینطور. سناریوهای «ورود موفق»، «رمز اشتباه»، «خروج»، «مسیر محافظت‌شده» (`DashboardTest.php`)، «کاربر با 2FA فعال به چالش هدایت می‌شود»، و «Rate Limit» همگی از قبل در `tests/Feature/Auth/AuthenticationTest.php` و `DashboardTest.php` موجود و سبز بودند.

### ترجمه صفحات Auth به فارسی RTL

طبق D10، هفت صفحه مسیر ورود/ثبت‌نام/بازیابی/۲FA (`resources/js/pages/auth/{login,register,forgot-password,reset-password,verify-email,confirm-password,two-factor-challenge}.tsx`) که هنوز انگلیسی بودند، به فارسی ترجمه شدند (متن، Placeholder، عنوان `Head`، `layout.title/description`). Layout‌های مشترک (`auth-layout` → `auth-simple-layout`) هیچ متن Hardcode‌شده‌ای نداشتند. در همین مسیر، یک نمونه از ریسک ثبت‌شده در P0-01 (کلاس فیزیکی `ml-auto` در دکمه «فراموشی رمز عبور») به کلاس منطقی `ms-auto` تغییر کرد چون دقیقاً همان خط ترجمه می‌شد؛ بقیه اصلاح فیزیکی→منطقی طبق تصمیم قبلی برای Sprint 3 می‌ماند.

⚠️ **یادداشت برای بعد، نه تصمیم:** ثبت‌نام عمومی (`register`) هنوز باز است. چون هی‌مد یک ابزار داخلی ۵ نفره است (نه SaaS)، احتمالاً در Sprint 8 (P8-05 «کاربران واقعی») باید بسته یا محدود به Invite شود — این یک تصمیم معماری است که نباید خودسرانه در این گام گرفته می‌شد، فقط اینجا برای تصمیم‌گیری بعدی یادداشت شد.

### تکمیل Timestamptz برای `passkeys` (شکاف باقیمانده از P0-05)

سه ستون `passkeys.{created_at,updated_at,last_used_at}` که در P0-05 عمداً نگه داشته شده بودند («چون Passkey بخشی از Authentication است»)، حالا با Migration جدید (`convert_passkeys_timestamps_to_timestamptz`) به `timestamptz` تبدیل شدند. بعد از این، در کل Schema هیچ ستون `timestamp without time zone` باقی نمانده به‌جز `jobs`/`job_batches` (که عمداً Unix Integer داخلی Queue هستند، نه Timestamp واقعی).

### «2FA اجباری برای Owner/Manager» — عمداً اینجا پیاده نشد

بخش ۲۱ PRD این را زیر «چک‌لیست سخت‌سازی» فهرست کرده که دقیقاً معادل Backlog Item **P8-01** (Sprint 8، نه Sprint 0) است؛ enforcement آن هم نیازمند رابطه واقعی کاربر↔نقش است که در P0-07 ساخته می‌شود و در Sprint 8 با کاربران واقعی (P8-05) پر می‌شود. اجرای زودهنگام آن اینجا یا duplicate‌کاری با P0-07 بود یا Enforcement روی داده جعلی. تصمیم: طبق ترتیب Backlog خود PRD، به P8-01 موکول شد؛ این یک انحراف از Scope نیست.

### تأیید

Full test suite: ۱۰۹ سبز. `npm run build` سبز. `tsc --noEmit` سبز. Pint/PHPStan سبز (تنها هشدار باقیمانده `npm run check` مربوط به فرمت جدول‌های Markdown در خود `PRD.md` است — فایل منبع حقیقت پروژه، از قبل همینطور بوده و در این گام دست‌نخورده ماند، چون تغییر آن خارج از اختیار این گام است).

## P0-07 — Permissions (CRITICAL)

پیاده‌سازی اختصاصی طبق تصمیم C8/بخش ۲۰ PRD؛ Spatie اضافه نشد.

### محل کد

کد جدید زیر `app/Modules/Core/{Enums,Models,Services}` قرار گرفت — اولین کد واقعی داخل ساختار Module بخش ۰۱ CLAUDE.md. دو تصمیم مرزی آگاهانه:
- **`App\Models\User` جابه‌جا نشد.** طبق قانون مرز ماژول فقط `Customers\Models\Customer` استثنا دارد، ولی `User` اصلاً داخل هیچ Moduleای نیست — یک Entity سطح Framework است که هر جدول Core (role_user، permission_overrides.user_id، audit_logs.user_id، settings.updated_by) به آن ارجاع می‌دهد. جابه‌جایی آن به `app/Modules/Core/Models` یعنی بازنویسی Fortify Config، UserFactory، و ده‌ها فایل موجود و تست‌شده — تغییر معماری بزرگ و خارج از Scope «Permissions». `Role`/`Permission`/`PermissionOverride` به `App\Models\User` ارجاع می‌دهند؛ این مشابه استثنای `Customer` در نظر گرفته شد، نه نقض قانون مرز.
- **PermissionService به‌جای Policy واقعی:** لایه Model Policy در PRD ذکر شده، ولی چون هنوز هیچ Model محافظت‌شدنی (Customer/Order/...) وجود ندارد، Policy واقعی در این گام ساخته نشد. `PermissionService::allows()` طوری نوشته شده که از داخل هر Policy آینده (Sprint 1+) هم قابل فراخوانی است.

### منطق (TEST FIRST)

`PermissionService::allows(User, module, action)`:
1. اگر Permission در Catalog نباشد → `false` (بسته پیش‌فرض).
2. اگر `permission_overrides` رکورد دارد (تضمین‌شده حداکثر یکی به‌خاطر `UNIQUE(user_id,permission_id)`) → دقیقاً همان اثر (allow/deny) برمی‌گردد و **کار تمام می‌شود** — یعنی گام‌های ۱ و ۲ ترتیب PRD («deny > allow») به‌صورت طبیعی از همین قید یکتایی نتیجه می‌شوند: یک کاربر هرگز هم‌زمان Override allow و deny روی یک Permission ندارد.
3. وگرنه، اگر نقشی که Grant می‌دهد دارد → `true`.
4. وگرنه → `false`.

`tests/Feature/Modules/Core/PermissionServiceTest.php` (۱۳ تست، TEST FIRST — قبل از نوشتن Service اجرا و قرمز شدن‌شان تأیید شد) دقیقاً سناریوی بحرانی خود CLAUDE.md را پوشش می‌دهد: کاربری با نقش Manager که `segments.delete` می‌گیرد ولی Override او `deny` است → **DENY**. به‌علاوه: Override مستقل از نقش، Scope دقیق per-user/per-permission، و `allowedKeys()` (برای لایه UI).

### لایه‌های اعمال

- **Route Middleware:** `App\Http\Middleware\EnsurePermission` (alias نام `permission`، در `bootstrap/app.php`) — `abort(403)` واقعی. تست در `EnsurePermissionMiddlewareTest.php` روی یک Route موقتِ داخل تست (چون هنوز Route محافظت‌شدنی واقعی وجود ندارد).
- **Inertia Shared `auth.permissions`:** در `HandleInertiaRequests::share()`، آرایه‌ای از کلیدهای `"module.action"` که کاربر لاگین‌کرده مجاز است (از `PermissionService::allowedKeys()`) به هر صفحه فرستاده می‌شود.
- **React `can()`:** هوک `resources/js/hooks/use-can.ts`، فقط از همین Prop می‌خواند — طبق تصریح PRD فقط UX است، مرز امنیتی واقعی نیست.
- **ماسک موبایل:** مجوز `customers.view_full_phone` در Catalog Seed شد؛ مکانیزم واقعی Mask کردن به Sprint 3 (وقتی UI مشتری ساخته می‌شود) موکول است — چیزی برای Mask کردن هنوز وجود ندارد.

### یافته فنی حین نوشتن تست Middleware

ثبت پویای یک Route داخل تست با الگوی معمول `Route::get(...)->name(...)` باعث شد `route('...')` خطای «Route not defined» بدهد با اینکه `Route::getRoutes()->count()` مسیر را نشان می‌داد. علت: `RouteCollection::add()` نام مسیر را در لحظه‌ی افزودن ایندکس می‌کند، نه بعد از `->name()`؛ `refreshNameLookups()` فقط یک‌بار در بوت اپلیکیشن (بعد از بارگذاری `routes/web.php`) صدا زده می‌شود، نه بعد از ثبت پویا در میانه تست. راه‌حل: در `EnsurePermissionMiddlewareTest.php` از URL خام به‌جای `route()` استفاده شد — این یک محدودیت شناخته‌شده تست‌نویسی روی این نسخه Laravel است، نه باگ کد پروژه.

### Catalog و ماتریس نقش (Seeders)

چون بخش ۲۰ PRD ماتریس را به‌صورت کیفی («همه»، «view + …») توصیف کرده نه به‌صورت لیست دقیق `module.action`، یک Catalog صریح از روی متن PRD استخراج شد (`database/seeders/PermissionSeeder::catalog()`) — شامل هر مورد Named شده (`customers.view_full_phone`, `customers.export`, `customers.anonymize`, `identity.review`, `ai.request`, `segments.{view,create,edit,delete}`) به‌علاوه یک `view` برای هر Module ذکرشده در ماتریس (`audit`, `settings→manage`, `users→manage`, `customers`, `orders`, `metrics`, `dashboard`, `analytics`, `ai`) و `customers.note` برای Support. این یک **پیش‌فرض صریح مستندشده** است، نه استنباط بی‌رویه: هر Module واقعی (Customers در P1-01، Sync در P2، …) وقتی ساخته شود Permissionهای دقیق‌تر خودش را به همین Catalog اضافه می‌کند.

`RoleSeeder` این ماتریس را دقیقاً پیاده می‌کند (Owner=همه، Manager=همه به‌جز `audit`/`settings`/`users`، Analyst=هر `*.view` + سه مورد اضافه بدون هیچ `*.delete`، Support=سه Permission ثابت، Viewer=چهار Permission ثابت) و Idempotent است (`firstOrCreate` + `sync`). `tests/Feature/Modules/Core/RoleSeederTest.php` (۷ تست) هر ردیف ماتریس + Idempotency را تأیید می‌کند. `DatabaseSeeder` این دو Seeder را قبل از کاربر تستی صدا می‌زند.

### تأیید

`migrate:fresh --seed` سبز (۵ نقش، Catalog کامل). Full Suite: ۱۳۵ سبز (۳۶۰ Assertion). Pint/PHPStan/`tsc --noEmit`/`npm run build` سبز.

## P0-08 — Audit

- `App\Modules\Core\Services\AuditService::record()` تنها مسیر نوشتن در `audit_logs` است (Controllerها هرگز مستقیم نمی‌نویسند). `paginate()` برای صفحه Audit است. `App\Modules\Core\Models\AuditLog` + Enum `AuditActorType` (`user|system|ai`).
- **Redaction:** قبل از ذخیره، هر کلید `before`/`after` که شامل یکی از این زیررشته‌ها باشد (بدون حساسیت به حروف) با `[REDACTED]` جایگزین می‌شود: `password`، `remember_token`، `secret` (پوشش `two_factor_secret`، `consumer_secret`)، `recovery_code`، `consumer_key`، `api_key`، `token`، `config` (چون `integrations.config` اعتبارنامه Woo/AI را نگه می‌دارد). یک تست حین نوشتن نشان داد `two_factor_recovery_codes` با لیست اولیه پوشش داده نمی‌شد؛ `recovery_code` اضافه شد.
- **Trait `Auditable`** (`Modules/Core/Concerns`): روی مدل‌های حساس گذاشته می‌شود و `created/updated/deleted` را خودکار (با diff فقط فیلدهای تغییرکرده) لاگ می‌کند. عامل: کاربر لاگین‌کرده → `user`، وگرنه `system` (`ai` فقط با فراخوانی صریح ماژول Ai). فعلاً روی `PermissionOverride` (حساس‌ترین جدول Core فعلی) اعمال شد؛ Setting در P0-09.
- ⚠ **محدودیت Schema:** `audit_logs.auditable_id` طبق PRD `bigint` است ولی `settings.key` رشته‌ای است. تا P0-09 تصمیم جداگانه‌ای برای Audit Setting گرفته می‌شود (نه اینجا).
- **صفحه Audit:** `GET /audit` (`AuditLogController@index` → `AuditService::paginate()`؛ Controller منطق ندارد)، محافظت‌شده با `auth` + `permission:audit,view`. صفحه React `pages/audit/index.tsx` فارسی RTL با صفحه‌بندی دستی. **`dangerouslySetInnerHTML` عمداً استفاده نشد** (قانون CLAUDE.md §2) — نسخه اول برچسب HTML صفحه‌بندی Laravel را با آن رندر می‌کرد و قبل از Commit با «قبلی/بعدی» ساده جایگزین شد. کامپوننت `ui/table.tsx` دستی اضافه شد (CLI شادسی‌ان به‌خاطر نبودن `pnpm` شکست خورد) با کلاس‌های منطقی RTL. لینک سایدبار فقط با `useCan('audit','view')` نمایش داده می‌شود.
- ⚠ `php artisan wayfinder:generate` دستی، Helperهای `.form()` را حذف می‌کند؛ فقط `npm run build` (پلاگین Vite) صحیح تولید می‌کند.
- تست‌ها: `AuditServiceTest` (۷)، `AuditableTraitTest` (۳)، `AuditLogPageTest` (۳: مهمان/بدون مجوز/مجاز). Suite: ۱۴۸ سبز، Pint/PHPStan/tsc/build سبز.

## P0-09 — Settings + AlertService

- **`SettingService::get/set`** (`Modules/Core/Services`): کلیدها فقط از Enum `SettingKey` می‌آیند (نوع، پیش‌فرض، قوانین اعتبارسنجی)؛ `set()` با Validator رد می‌کند و چیزی ذخیره نمی‌شود. خواندن با `Cache::rememberForever` و ابطال کش در `set()`. تغییر واقعی → `updated_by`، رکورد Audit (`setting.updated` با before/after) و Event `SettingChanged`؛ مقدار یکسان → هیچ‌کدام. کلیدها: `alerts.enabled`، `alerts.dedupe_minutes`، `alerts.failed_jobs_threshold` (۲۰)، `alerts.consecutive_sync_failures` (۲)، `alerts.reconciliation_diff_percent` (۱٫۰) — همان آستانه‌های بخش ۲۲ PRD.
- **Secretها در Settings نیستند:** طبق CLAUDE.md §6 اعتبارنامه Woo/AI رمزنگاری‌شده در `integrations.config` (Sprint 2) می‌ماند؛ هیچ کلید Settings حساس تعریف نشد و Audit هم هر کلید حساس را Redact می‌کند. بودجه AI و حاشیه سود طبق PRD در `config/ai.php`/`config/metrics.php` می‌مانند، نه Settings (جلوگیری از دو منبع حقیقت).
- **تصمیم Audit برای کلید رشته‌ای:** `audit_logs.auditable_id` طبق PRD `bigint` است ولی `settings.key` رشته → `auditable_id = 0` و کلید داخل payload `before/after` ثبت می‌شود (Schema دست نخورد؛ اگر بعداً Audit روی Setting باید با `auditable_id` قابل جستجو باشد، Migration جدید لازم است).
- **`AlertService::critical(AlertKind, message, context)`**: شش نوع طبق PRD (`sync_failure`، `metric_run_failure`، `reconciliation_variance`، `failed_jobs_threshold`، `ai_budget_threshold`، `nightly_chain_timeout`). چون Phase 1 هیچ کانال اعلان (SMS/Telegram/Email) ندارد، هشدار = `Log::critical` + رکورد Audit با `alert.critical` (در صفحه Audit دیده می‌شود). Dedupe با `Cache::add` به‌ازای هر نوع در پنجره `alerts.dedupe_minutes`؛ با `alerts.enabled=false` خاموش است؛ خروجی `bool` (اعلام‌شد/نه). Schedulerهایی که این را صدا می‌زنند (Sprint 6) هنوز ساخته نشده‌اند.
- **اصلاح جانبی:** Redaction در `AuditService` بازگشتی شد (Context تودرتوی هشدار، مثل `consumer_secret` داخل `context`، قبلاً Redact نمی‌شد).
- تست: `SettingServiceTest` (۱۱)، `AlertServiceTest` (۶). Suite: ۱۶۹ سبز، Pint/PHPStan سبز.

## P0-10 — تست‌های معماری

`tests/Arch` (سوئیت `Arch` در `phpunit.xml`؛ بدون DB). به‌جای `arch()` خود Pest از یک اسکنر مبتنی بر Token (`tests/Arch/Scanner.php`) استفاده شد که کامنت/DocBlock را حذف می‌کند تا متن مستندات (مثل «ممنوع: DB::raw») هشدار الکی ندهد؛ `ScannerTest` خودِ اسکنر را اثبات می‌کند. قوانین (`ArchitectureTest.php`):

1. Controller بدون DB/Query (`DB::`، `::where/query/find/create…`، `->where/save/update/delete/select…`).
2. Controller هرگز Model ماژول را مستقیم `use` نمی‌کند (Controller→Service→Model).
3. Job بدون منطق کسب‌وکار (همان الگوها + Model ماژول).
4. ماژول‌ها Model یکدیگر را نمی‌بینند؛ تنها `Customers\Models\Customer` مجاز است.
5. ارجاع بین‌ماژولی فقط به `Services` یا `Events` (سخت‌گیرانه طبق CLAUDE.md؛ اگر Enum مشترک لازم شد باید صریحاً بحث و اضافه شود).
6. جدول وابستگی ماژول‌ها دقیقاً طبق بخش ۰۷ PRD.
7. `Segments`: هیچ `whereRaw/selectRaw/…/DB::raw/Expression`.
8. Raw SQL فقط در Migration و `Metrics`/`Analytics` (کل `app/`، `routes`, `config`).
9. بدون `dd/dump/ray/var_dump/print_r`، و در فرانت بدون `console.log/debugger/dangerouslySetInnerHTML`.
10. تست‌ها روی SQLite/`:memory:` نیستند (phpunit.xml، `.env.testing`، `.env.example`، فایل‌های تست).
11. `.env*` در Git نیست و هیچ کلید Woo (`ck_/cs_`)، کلید AI، یا Private Key در فایل‌های ردیابی‌شده نیست.
12. `declare(strict_types=1)` در `app/Modules` و `app/Support`؛ رشته وضعیت سفارش Hardcode نشده.

**Mutation check:** نقض عمدی (Job با DB، `whereRaw` در Segments، `use` مدل ماژول دیگر، `dd()`) موقتاً کاشته شد؛ ۷ تست شکست خوردند و پس از حذف همه سبز شدند.

### تخلف‌های واقعی که تست‌ها در کد استارتر پیدا کردند و رفع شدند
- `ProfileController` (`save`, `delete`) و `SecurityController` (`update` رمز، Query لیست Passkey) مستقیم در Controller به DB می‌نوشتند/می‌خواندند → به `Modules/Core/Services/UserAccountService` منتقل شد (رفتار و تست‌های موجود بدون تغییر).
- `two-factor-setup-modal.tsx` کد QR را با `dangerouslySetInnerHTML` رندر می‌کرد → با `<img src="data:image/svg+xml…">` جایگزین شد (SVG داخل `img` اسکریپت اجرا نمی‌کند).
- کامنت `phpunit.xml` که خودش کلمه SQLite داشت بازنویسی شد.

Suite: ۱۸۶ سبز؛ Pint/PHPStan/tsc/build سبز.

## GATE 0 — نتیجه

- Login/Logout/مسیر محافظت‌شده/۲FA (چالش، کد نامعتبر، کد بازیابی نامعتبر، Throttle): ۱۲ تست سبز + Smoke زنده روی سرور واقعی (`/login` ۲۰۰ با `lang="fa" dir="rtl"`، `/dashboard` و `/audit` برای مهمان ۳۰۲ به `/login`).
- **Deny-override:** کاربر با نقش Manager که `segments.delete` می‌گیرد ولی Override=`deny` دارد → DENY (تست `CRITICAL` + ۱۳ تست PermissionService + Middleware) سبز.
- **Arch:** ۱۷ تست سبز، با Mutation check اثبات‌شده.
- Full suite ۱۸۶ سبز؛ `pint --test` کل مخزن، PHPStan (Larastan)، `tsc --noEmit`، `npm run build` سبز (`verify-woo.php` فقط Format شد).
- باز/تصمیم‌های منتظر: (۱) ثبت‌نام عمومی هنوز باز است؛ (۲) ۲FA اجباری Owner/Manager = P8-01؛ (۳) `npm run check` روی جدول‌های Markdown خود `PRD.md` هشدار می‌دهد (فایل منبع حقیقت، دست‌نخورده)؛ (۴) بخش‌های Settings/Security UI هنوز انگلیسی است (Sprint 3).

## P1-01 — Customers + identities + conflicts + addresses + notes

Migrationهای ۰۱۲–۰۱۶ بخش ۰۹ PRD و ماژول `app/Modules/Customers` (`Models`, `Enums`) + `CustomerFactory`.

- **`customers`:** دقیقاً طبق Schema (`phone_normalized` یکتا، `status`/`lifecycle_stage` با CHECK، `metrics_dirty` پیش‌فرض true، Soft delete، همه Timestamp از نوع `timestamptz`). Indexهای PRD: جزئی `WHERE metrics_dirty = true` و GIN `gin_trgm_ops` روی `display_name`. **هیچ شمارنده تجمیعی روی جدول نیست** (C7) — تست Schema تضمین می‌کند.
- **`customer_identities`:** `UNIQUE(source, source_id)` (حتی بین دو مشتری)؛ `source` CHECK (`woo_user|woo_guest_order`).
- **`identity_conflicts`:** `woo_order_id` عمداً FK نیست (جدول `orders` در P1-03 می‌آید و تعارض باید مستقل از آن بماند)؛ `resolved_by` با حذف کاربر NULL می‌شود.
- **`customer_addresses`** (`type` CHECK) و **`customer_notes`** (`user_id` nullable + `SET NULL`: یادداشت‌ها از Woo قابل بازیابی نیستند، پس با حذف نویسنده باقی می‌مانند).
- FK فرزندها به `customers` با `CASCADE` است چون حذف واقعی مشتری در Phase 1 اتفاق نمی‌افتد (Soft delete/Anonymize)؛ سفارش‌ها در P1-03 `RESTRICT` خواهند بود.
- **پیش‌فرض‌های صریح (PRD ساکت بود):** (۱) `customer_identities.confidence` مقدارش را PRD نگفته؛ CHECK روی `high|medium|low` گذاشته شد (قاعده «هر ستون Enum یک CHECK») — در P2-04 در صورت نیاز تغییر می‌کند؛ (۲) Enumهای همین جدول‌ها (`CustomerStatus`, `LifecycleStage`, `IdentitySource`, `IdentityConfidence`, `IdentityConflictStatus`, `AddressType`) همین‌جا ساخته شدند چون Model بدون Enum «رشته لخت» می‌شد؛ P1-05 بقیه Enumها را اضافه می‌کند.
- **Mutator نرمال‌سازی:** `Customer::phone_normalized` هر مقدار نوشته‌شده را از `PhoneNormalizer` رد می‌کند؛ `+98 912…` و `0912…` هرگز دو مشتری نمی‌شوند و موبایل نامعتبر Exception می‌دهد (تست‌شده). منطق Resolve/Conflict (`CustomerIdentityService`) عمداً P2-04 است و اینجا ساخته نشد.
- **Auditable Trait روی این جدول‌ها اعمال نشد:** Trait در ماژول Core است و استفاده مستقیم از آن در Customers قانون مرز ماژول (فقط Service/Event) را نقض می‌کند؛ Audit تصمیم‌های بازبینی هویت در P2-04/Sprint 3 از طریق `AuditService` انجام می‌شود.
- تست: `tests/Integration/CustomersSchemaTest.php` (Constraintها، Indexها، بدون شمارنده، `timestamptz`) و `tests/Feature/Modules/Customers/CustomerModelTest.php` (Factory، نرمال‌سازی، رابطه‌ها، Soft delete). `migrate:rollback --step=5` و `migrate:fresh --seed` سبز. Suite: ۲۱۶ سبز، Pint/PHPStan/tsc/Arch سبز.

## P1-02 — Catalog

Migrationهای ۰۱۷–۰۲۱ بخش ۰۹ PRD (`product_categories`, `products`, `product_category_product`, `product_variations`, `product_costs`) و ماژول `app/Modules/Catalog` (`Models`, `Enums`) + Factoryها. فقط داده آینه‌ای Woo است؛ `CatalogService::upsertProduct/resolveVariationBySku` مربوط به Sync (P2-05/P2-06) است و ساخته نشد.

- Schema دقیقاً طبق PRD: `woo_*_id` یکتا، Index جزئی یکتای `sku WHERE sku IS NOT NULL`، `attributes` از نوع `jsonb` با پیش‌فرض `{}`، GIN `gin_trgm_ops` روی `products.name`، `price`/`unit_cost` از نوع `bigint` (تومان صحیح)، همه Timestamp از نوع `timestamptz`، `product_costs` خالی ساخته می‌شود (D11).
- **پیش‌فرض‌های صریح (PRD مقادیر Enum را نگفته):** `products.type` ∈ `simple|variable|grouped|external` و `products.status`/`product_variations.status` ∈ `publish|draft|pending|private` (مجموعه هسته Woo) با CHECK طبق قاعده «هر ستون Enum یک CHECK»؛ `product_costs.source` فقط `manual` (تنها مقدار PRD). ⚠ **الزام برای P2-05:** Mapper باید هر نوع/وضعیت خارج از این مجموعه (مثلاً نوع محصول افزونه‌ای) را نگاشت کند، نه اینکه ردیف رد شود؛ مقدار جدید = Migration جدید.
- **FKها:** واریانت‌ها با حذف محصول و پیوندهای دسته با حذف هر طرف Cascade می‌شوند؛ `parent_id` دسته با حذف والد NULL می‌شود؛ `product_costs.variation_id` عمداً `RESTRICT` است (هزینه دستی از Woo قابل بازیابی نیست، حذف بی‌صدا نباید ممکن باشد).
- **نام ستون `attributes`:** طبق PRD ثابت است ولی با ویژگی داخلی `Model::$attributes` Eloquent هم‌نام است. کار می‌کند (Cast `array`) و با تست خواندن/به‌روزرسانی/Refresh/`toArray`/`whereJsonContains` قفل شد؛ در کد آینده Sync از `->attributes` فقط از طریق Model استفاده شود، نه دستکاری `$model->attributes` خام.
- تست: `tests/Integration/CatalogSchemaTest.php` (۲۰: Constraintها، Indexها، NULLهای متعدد در SKU/Woo id، `bigint`، RESTRICT/CASCADE، `timestamptz`) و `tests/Feature/Modules/Catalog/CatalogModelTest.php` (۷). `migrate:rollback --step=5` / `migrate` / `migrate:fresh --seed` سبز. Suite: ۲۴۳ سبز، Pint/PHPStan/tsc/Arch سبز.

## P1-03 — Orders + items + status history + refunds

Migrationهای ۰۲۲–۰۲۵ بخش ۰۹ PRD و ماژول `app/Modules/Orders` (`Models`, `Enums`) + `OrderFactory`. منطق Sync (Upsert سفارش، بازمحاسبه `refunded_total`، تعیین `is_realized`) عمداً Sprint 2 است و ساخته نشد.

- Schema دقیقاً طبق PRD: `woo_order_id`/`woo_refund_id` یکتا (Idempotency)، `UNIQUE(order_id, woo_item_id)`، `customer_id` با `RESTRICT`، آیتم‌ها/تاریخچه/عودت با حذف سفارش `CASCADE`، `CHECK (total >= 0 AND refunded_total >= 0)`، Index نزولی `(customer_id, ordered_at DESC)` و Index جزئی `WHERE is_realized AND deleted_at IS NULL`، Soft delete (D6)، همه پول `bigint` و همه زمان `timestamptz`.
- **`net_revenue`** با `ALTER TABLE … GENERATED ALWAYS AS (total - refunded_total) STORED` و `DB::statement()` (قانون ۳ Migration) ساخته شد؛ در `$fillable` Model نیست و تست‌ها نوشتن مستقیم را رد و `attgenerated='s'` را تأیید می‌کنند.
- **پیش‌فرض‌های صریح (PRD ساکت بود):**
  1. `orders.status` و `order_status_history.from/to_status` رشته خام Woo هستند، **بدون CHECK و بدون Enum سمت اپ**: وضعیت‌ها را فروشگاه تعریف می‌کند (سفارش با وضعیت سفارشی نباید رد شود — CLAUDE.md §5) و CLAUDE.md §3 نوشتن رشته وضعیت در کد را ممنوع کرده؛ «تحقق‌یافته بودن» از `config('woo.realized_statuses')` می‌آید. `config/woo.php` هنوز وجود ندارد (P2-01) و همین‌جا ساخته نشد.
  2. `order_status_history.source` فقط `sync` (تنها مقدار PRD) با CHECK و Enum `OrderHistorySource`؛ مقدار جدید = Migration جدید.
  3. `order_items.product_id/variation_id` با حذف ردیف کاتالوگ `SET NULL` می‌شوند (آیتم و درآمد باید بمانند). Orders **رابطه Eloquent به Catalog ندارد** (`OrderItem::product()` عمداً نیست)؛ فقط شناسه، و Resolve از طریق سرویس Catalog در Sprint 2. Order فقط به `Customers\Models\Customer` (استثنای مجاز) ارجاع دارد و `Customer::orders()` عمداً اضافه نشد (Customers نباید Model Orders را ببیند).
  4. Indexهای FK اضافه‌ای که PRD نگفته: `order_items(product_id)`, `order_items(variation_id)`, `order_status_history(order_id, changed_at)`, `refunds(order_id)` — PostgreSQL برای FK خودکار Index نمی‌سازد و حذف/Join روی آن‌ها بدون Index Seq Scan می‌شد.
  5. `refunded_total ≤ total` و `SUM(refunds.amount) = refunded_total` در DB اعمال نمی‌شود (PRD فقط `>= 0` گفته)؛ تضمین آن با بازمحاسبه SQL در Sprint 2 است.
- **اصلاح در Arch Test (خطای خودم در P0-10):** قانون «فقط Service/Event بین ماژول‌ها» نام کلاس را Capture نمی‌کرد و استثنای `Customers\Models\Customer` هرگز نمی‌توانست Match شود؛ چون تا حالا هیچ ماژولی Customer را نمی‌خواند دیده نشده بود. اصلاح شد (فقط دقیقاً `Customer`، نه `CustomerAddress`) و با کاشت تخلف (`CustomerAddress`، Model Catalog) دوباره اثبات شد.
- تست: `tests/Integration/OrdersSchemaTest.php` (۳۰: Constraintها، ستون Generated، `bigint`، RESTRICT/CASCADE/SET NULL، Indexها، `timestamptz`، Idempotency) و `tests/Feature/Modules/Orders/OrderModelTest.php` (۱۰: عودت جزئی/کامل، `net_revenue`، وضعیت سفارشی، تاریخچه، Soft delete). `migrate:rollback --step=4` / `migrate` / `migrate:fresh --seed` سبز. Suite: ۲۸۳ سبز.

## P1-04 — Migrationهای Metrics / Segments / Analytics / Sync / AI

فقط Schema (۱۷ Migration، شماره‌های ۰۲۶–۰۴۲ بخش ۰۹ PRD): `metric_runs`, `customer_metrics`, `segments`, `segment_members`, `daily_metrics`, `cohort_snapshots`, `product_affinities`, `customer_category_purchases`, `customer_product_purchases`, `sync_cursors`, `sync_jobs`, `sync_logs`, `reconciliation_reports`, `integrations`, `ai_insights`, `ai_usage_daily`, `ai_tool_calls`. **هیچ Model/Enum/Service/Job ساخته نشد** — PRD هیچ‌کدام را به P1-04 نسبت نداده (Enumها = P1-05؛ منطق = Sprintهای ۲ تا ۷).

- Schema دقیقاً طبق PRD: نوع ستون‌ها (`bigint` تومان، `numeric(p,s)` برای امتیاز/نرخ/هزینه دلار، `jsonb`، `smallint`، همه `timestamptz`)، PK/UNIQUE، CHECKهای صریح (امتیاز ۱..۵، `mode`/`status`/`type`/`level`، `entity_a_id <> entity_b_id`، «سگمنت dynamic حتماً `rule` دارد»)، Indexهای PRD (`monetary DESC`، `(level, entity_a_id, lift DESC)`، یکتای جزئی `lower(name) WHERE deleted_at IS NULL` و `cache_key WHERE cache_key IS NOT NULL`).
- **پیش‌فرض‌های صریح (PRD ساکت بود):**
  1. `migration ۰۴۳` (نقش `hm_ai_readonly`) در P1-04 **ساخته نشد**: Backlog آن را به P7-01 (GATE 4، تست «INSERT باید شکست بخورد») سپرده، نیازمند `CREATEROLE` و رمز است. همین‌طور `ai_actions` طبق §19 عمداً وجود ندارد (تست دارد).
  2. FKها: جدول‌های مشتق (`customer_metrics`, `segment_members`, `customer_*_purchases`) با حذف والد `CASCADE` (قابل بازسازی‌اند)؛ `metric_run_id`, `segments.created_by`, `ai_insights.requested_by` → `SET NULL` (سگمنت/بینش با حذف کاربر یا Run نمی‌میرد)؛ `sync_logs`/`ai_tool_calls` با حذف والد `CASCADE`. `product_affinities.entity_*_id` چندشکلی (بسته به `level`) است → `bigint` ساده، نه FK.
  3. CHECK روی مقادیر Enum که PRD لیست نکرده: `customer_metrics.rfm_segment` = هشت سگمنت §۱۲؛ `sync_cursors.last_status` = مجموعه `sync_jobs.status`؛ `sync_logs.level` = هشت سطح استاندارد PSR-3 تا هر سطح `Log` لاراول بدون Migration جدید نگاشت شود. **بدون CHECK عمداً:** `sync_*.entity` (شناسه است نه وضعیت) و `integrations.last_health_status` (PRD مقداری نداده و مصرف‌کننده‌اش HealthCheckJob در Sprint 6 است).
  4. نوع/Default هایی که PRD نگفته: `ai_insights.period_start/end` = `date`؛ شمارنده‌های `sync_jobs` و توکن‌های `ai_insights` و ستون‌های `ai_usage_daily` `DEFAULT 0`؛ `ai_tool_calls.arguments` `DEFAULT '{}'`؛ `computed_at`/`started_at`/`added_at`/`created_at` `DEFAULT CURRENT_TIMESTAMP`؛ ستون‌های «بدون Default» در Rollupها (`daily_metrics` و…) `NOT NULL` بدون Default (سازنده Sprint 6 همیشه ردیف کامل می‌نویسد).
  5. **در DB اعمال نشد:** قواعد Metrics مثل «`churn_reason` وقتی سطح ریسک هست الزامی» و «`clv_estimated` وقتی `total_orders < 2` NULL» و «Affinity با `lift <= 1` ذخیره نشود» — در PRD جزو CHECKهای Schema نیستند و ترتیب چندمرحله‌ای بازمحاسبه (§۱۱) ممکن است حین اجرا موقتاً آن‌ها را نقض کند؛ تضمینشان با تست Calculatorها (Sprint 4/6) است.
- تست (PostgreSQL واقعی، `tests/Integration`): `SchemaProbe` کاتالوگ PostgreSQL را می‌خواند و برای هر جدول **همه ستون‌ها را با نوع/Nullable/Default مقایسه می‌کند و ستون اضافه را هم خطا می‌داند**؛ به‌علاوه `MetricsSchemaTest`, `SegmentsSchemaTest`, `AnalyticsSchemaTest`, `SyncSchemaTest`, `AiSchemaTest`, `Phase1TimestampSchemaTest` (CHECK/UNIQUE/FK با `ON DELETE` دقیق، Indexهای جزئی و نزولی، `timestamptz`، نبود `float`). با یک ستون نادرست عمدی، اثبات شد Probe خطا می‌دهد.
- `migrate:rollback --step=17` هر ۱۷ جدول را برمی‌دارد، `migrate` برمی‌گرداند، `migrate:fresh --seed` سبز؛ هیچ Migration قبلی ویرایش نشد. Suite: ۳۷۳ سبز.

## P1-05 — Enumها

۱۱ Enum برای ستون‌های Enum-مانند جدول‌های P1-04 که هنوز Enum نداشتند، هرکدام در ماژول مالک خودش (`Modules/*/Enums`، `string`-backed، `strict_types`): `Metrics` → `MetricRunMode`, `MetricRunStatus`, `RfmSegment` (به ترتیب CASE بخش ۱۲)، `ClvConfidence`, `ChurnRiskLevel`؛ `Segments` → `SegmentType`؛ `Analytics` → `AffinityLevel`؛ `Sync` → `SyncMode`, `SyncStatus` (برای `sync_jobs.status` و `sync_cursors.last_status`)، `SyncLogLevel`؛ `Ai` → `AiInsightType`. Enumهای P1-01..P1-03 و Core جابه‌جا/تکرار نشدند. فقط Case (بدون منطق/Service).

- **عمداً Enum نشد:** وضعیت سفارش (داده Woo، طبق CLAUDE.md §3/§5)، `sync_*.entity` (شناسه)، `integrations.last_health_status` (PRD مقداری نداده)، Operatorهای Segment (P5-01).
- **تست هم‌ترازی Enum↔DB:** `EnumSchemaAlignmentTest` برای هر ۲۵ ستون Enum-مانند (شامل Enumهای قبلی) مقادیر Enum را با مجموعه CHECK واقعی PostgreSQL مقایسه می‌کند — Enum و CHECK دیگر نمی‌توانند بی‌صدا از هم جدا شوند. (PostgreSQL یک `IN` تک‌مقداری را `= 'x'::text` چاپ می‌کند؛ Parser تست هر دو شکل را می‌خواند.)
- **Arch (`EnumConventionsTest`):** Enum فقط در `Modules/*/Enums`، `string`-backed، `strict_types`، نام یکتا در کل پروژه، بدون Enum وضعیت سفارش، و هیچ دو Enum مجموعه مقدار یکسان ندارند — با یک استثنای مستند: `ClvConfidence` (Metrics) و `IdentityConfidence` (Customers) مقدار یکسان `low/medium/high` دارند ولی معنا و ماژول متفاوت (Enum مشترک بین‌ماژولی ممنوع است).
- **اصلاح Arch Test قدیمی (نه سست‌کردن):** قانون «رشته وضعیت سفارش Hardcode نشود» روی `'completed'` در `MetricRunStatus`/`SyncStatus` (وضعیت Run/Job، نه سفارش Woo) خطا می‌داد؛ فقط همین دو فایل از اسکن مستثنا شدند و هر فایل دیگر (از جمله کل `Orders`) هنوز اسکن می‌شود. با کاشت `OrderStatus` Enum در Orders اثبات شد که همچنان گرفته می‌شود.
- Suite: ۴۴۸ سبز (Arch ۲۲).

## P1-06 — DemoDataSeeder + expected_metrics.json

`database/seeders/DemoDataSeeder.php` (بخش ۲۴ PRD: ۵۰ مشتری = ۲۰ تک‌خرید + ۱۵ تکرارخرید + ۸ وفادار + ۴ در معرض ریزش + ۳ دارای عودت) و `tests/fixtures/expected_metrics.json` (قرارداد GATE 2). فقط جدول‌های آینه‌ای (کاتالوگ، مشتری، سفارش، آیتم، تاریخچه، عودت) پر می‌شوند؛ جدول‌های مشتق/Sync/AI عمداً خالی می‌مانند (`metrics_dirty=true` برای هر ۵۰ مشتری).

- **قطعی بودن:** بدون ساعت، Random، Faker یا شبکه؛ همه‌چیز محاسبه‌ی حسابی روی اندیس مشتری (۱..۵۰) و ثابت `AS_OF = 2026-06-30 08:30 UTC`. تست مصرف `now()/rand()/fake()/Http::/WooClient` را در کد Seeder ممنوع می‌کند. دو بار `migrate:fresh --seed` هش داده (با `id`) یکسان می‌دهد؛ `db:seed` مجدد چیزی را تغییر نمی‌دهد (Seeder Idempotent است).
- **جدا از داده Production:** در `production` با Exception رد می‌شود و در `DatabaseSeeder` فقط `if (! app()->isProduction())` صدا زده می‌شود. شماره‌ها `989000000001..050` (ساختگی) و از `PhoneNormalizer` رد می‌شوند.
- **`config/woo.php` (فقط دو کلید):** `realized_statuses = ['processing','completed']` و `excluded_statuses` (بخش ۱۰) — چون CLAUDE.md §3 می‌گوید `is_realized` فقط از این Config می‌آید و Seeder نباید لیست Hardcode داشته باشد. تنظیمات اتصال/Retry/Webhook در P2-01 به همین فایل اضافه می‌شود.
- **پوشش شاخه‌ها:** سفارش لغوشده/pending/on-hold/failed (غیرتحقق‌یافته)، ۱ سفارش Soft-delete، ۱ سفارش عودت کامل با وضعیت `completed`، دو عودت بخشی (یکی مرجوعی قلم، یکی فقط مبلغ)، آیتم Unresolved با `product_id/variation_id = NULL` و `sku` + `name_snapshot`، دو `identity_conflicts` (pending و حل‌شده)، آدرس Billing/Shipping و یادداشت. قیمت آیتم‌ها از مجموع سفارش می‌آید (نه قیمت لیست کاتالوگ).
- **طراحی برای بی‌ابهامی عددی:** همه سفارش‌ها ساعت ۰۸:۳۰ UTC (فاصله‌ها روز صحیح)، همه مبالغ مضرب ۱۰٬۰۰۰ تومان، و درآمد شمارش‌شده هر مشتری مضرب دقیق تعداد سفارش‌هایش (AOV صحیح، بدون قاعده گرد‌کردن). Oracle به‌محض رسیدن به **تساوی دقیق ۰٫۵** (مثلاً امتیاز ریسک ۳۶٫۸۷۵) خطا می‌دهد؛ یک مورد (مشتری ۲۵) با یک‌روز جابه‌جایی فاصله‌ی گروه تکرارخرید رفع شد.
- **`expected_metrics.json`** (`as_of` تزریقی، هرگز `now()`؛ کلید = `phone_normalized`): Base aggregates، امتیازهای RFM با NTILE و اصلاح E3، `rfm_segment` (CASE بخش ۱۲)، `clv_historical/estimated/confidence` (بخش ۱۳)، `purchase_cycle_days`, `churn_risk_score/level`, `lifecycle_stage` (بخش‌های ۱۱/۱۴)، `cohort_month` و ماتریس Cohort (بخش ۱۵)، و مجموع‌های فروشگاه (نرخ خرید مجدد ۰٫۵۶۰۰). آستانه Churn: نمونه فاصله‌ها = ۶۰ (< ۲۰۰) پس **Fallback ۶۰/۱۲۰/۲۱۰** (بخش ۱۴)؛ مسیر Percentile واقعی با این داده پوشش داده نمی‌شود و باید در تست‌های Sprint 4 با داده‌ی ساختگی خودش بیاید.
- **دو پیاده‌سازی مستقل هم‌نتیجه‌اند:** (۱) Oracle آزمایشی PHP (`tests/Support/ReferenceMetrics.php`، محاسبه‌ی دقیق عدد صحیح/کسری) و (۲) همان فرمول‌های PRD به‌صورت SQL خالص PostgreSQL در `ExpectedMetricsFixtureTest` (شامل `ntile`, `percentile_cont`, `lag`, و توابع `to_jalali_month`/`jalali_month_diff` از P0-04). هر ۵۰ مشتری و هر ردیف Cohort در هر دو یکی است. با تغییر عمدی یک عدد Fixture و یک مبلغ Seeder، تست‌ها شکست خوردند.
- **Fixture قفل‌شده به داده:** `meta.dataset_fingerprint` (هش کلیدهای کسب‌وکاری، بدون `id`) با داده‌ی Seed فعلی مقایسه می‌شود؛ با تغییر Seeder تست می‌شکند تا Fixture **آگاهانه** بازبینی شود. بازتولید: Seed تازه + `ReferenceMetrics::compute(DemoDataSeeder::asOf())` + `datasetFingerprint()` و نوشتن JSON، سپس بازبینی Diff.
- **ابهام‌هایی که برای Sprint 4 باید صریح شوند (Fixture با حداقل فرض نوشته شد):** `monetary = SUM(net_revenue − shipping_total)` (D2)؛ `aov = total_revenue / total_orders`؛ `recency_days` نسبت به `as_of` تزریقی (Engine باید پارامتر `as_of` بپذیرد)؛ `distinct_categories` و `churn_reason` در Fixture نیستند (فرمت متن/تعریف در PRD نیامده). Segmentهای `cant_lose` و `lost` با گروه‌بندی الزامی PRD در داده دیده نمی‌شوند (نیازمند مشتری پرتکرار با قدیمی‌ترین Recency) و باید در تست واحد Sprint 4 با داده‌ی ساختگی بیایند.
- **اصلاح پیش‌موجود در `DatabaseSeeder`:** ساخت `Test User` با Factory در اجرای دوم `db:seed` با خطای یکتایی ایمیل می‌شکست؛ حالا فقط اگر وجود نداشته باشد ساخته می‌شود (تست رگرسیون دارد).
- تست: `DemoDataSeederTest` (۱۵) و `ExpectedMetricsFixtureTest` (۹). Suite: ۴۷۲ سبز.

## P2-01 — WooClient + HttpWooClient

فقط لایه انتقال (Transport) خواندنیِ Woo؛ هیچ منطق Sync، DTO، Mapper، Job یا Command ساخته نشد. کد در `app/Modules/Sync/{Services,Support,Exceptions}`.

- **مرز عمومی:** `Services\WooClient` (Interface) فقط دو متد دارد: `page(endpoint, page, ?SyncWindow, query)` و `pages(...)` (Generator). **هیچ متد نوشتنی وجود ندارد و نباید اضافه شود** (CLAUDE.md §0)؛ تست هم Interface و کلاس را با Reflection و هم کل ماژول Sync را برای فعل‌های نوشتنی HTTP اسکن می‌کند. پیاده‌سازی: `HttpWooClient` (Bind در `AppServiceProvider`، تنبل و نه Singleton: نبودن اعتبارنامه بوت اپ را نمی‌شکند و لحظه‌ی درخواست کلاینت `WooConfigurationException` می‌دهد). خروجی داده خام JSON است (`WooPage`)؛ نگاشت به DTO کار P2-03 است.
- **Endpoint فقط مسیر نسبی:** `^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$`؛ URL مطلق، `//host`، `..` و `?` رد می‌شود تا Basic auth هرگز به میزبان دیگر نرود. Redirect دنبال نمی‌شود (`withoutRedirecting`) و `base_url` باید HTTPS باشد. کلید هرگز در Query String نیست.
- **Frozen cursor = `Support\SyncWindow`:** مقدار غیرقابل‌تغییر؛ `freeze(cursorValue, overlap)` در شروع Job ساخته می‌شود: `modified_before = now()` (به ثانیه کف‌شده تا مقدارِ ذخیره‌شده‌ی Cursor دقیقاً همان مرز پرس‌وجوشده باشد) و `modified_after = cursor − overlap_minutes` (Cursor اول = بدون کران پایین). همان مقدار روی **همه** صفحه‌ها می‌رود و با گذر زمان جابه‌جا نمی‌شود (تست با پیشروی ساعت بین صفحه‌ها). Query طبق PRD: `modified_after/modified_before/orderby=modified/order=asc`؛ فیلتر ارسالی Caller هرگز `page/per_page/پنجره` را Override نمی‌کند. **پیشروی Cursor فقط با `completed` و در SyncService (P2-08) است**، نه اینجا.
- **Retry:** قابل‌تکرار = 429/500/502/503/504 + قطع اتصال/Timeout؛ نردبان `config('woo.retry_backoff_seconds') = [2,8,30,120]`، حداکثر `max_retries = 4` (یعنی ۵ درخواست). در 429 مقدار `Retry-After` (ثانیه یا HTTP-date) بر نردبان مقدم است؛ غایب/نامعتبر/صفر/گذشته → نردبان PRD. خطای خاتمه‌ای (`WooRequestException`، بدون Retry): 400/401/403/404 **و هر وضعیت دیگری که PRD قابل‌تکرار ندانسته** (مثلاً 3xx/405/409/422/501) — پیش‌فرض صریح چون PRD فقط لیست تکرار و چهار وضعیت را نام برده. Log: هر Retry = `warning`، هر شکست نهایی = `error` با `endpoint`، `status`، نام کلیدهای Query (نه مقدارها؛ ممکن است شماره موبایل باشد) و `woo_code`. متن خطای cURL عمداً Log/Rethrow نمی‌شود چون کل URL (با Query) را دارد.
- **پاسخ ناقص (PRD ساکت بود، پیش‌فرض صریح):** 2xx غیر JSON، غیر لیست، آیتم غیرآبجکت، نبودن/نامعتبر بودن `X-WP-TotalPages`، یا آیتم در صفحه‌ای فراتر از `TotalPages` → `WooMalformedResponseException` بدون Retry. هدر نبود را حدس نمی‌زنیم چون حدس اشتباه یعنی Sync ناقصِ بی‌صدا.
- **Rate limit = `Services\RedisTokenBucket`:** Token bucket واقعی روی Redis، یک اسکریپت Lua اتمیک، ظرفیت = `rate_limit_per_minute` (۹۰) و شارژ پیوسته ۱٫۵ توکن/ثانیه. حالت خالی: توکن بعدی **رزرو** می‌شود و Caller همان‌قدر Sleep می‌کند (صف عادلانه؛ ۹۱ام ۶۶۷ms، ۹۲ام ۱۳۳۴ms). هر تلاش HTTP از جمله Retryها یک توکن می‌گیرد. اگر Redis در دسترس نباشد Exception می‌دهد و **هیچ درخواستی فرستاده نمی‌شود** (Fail-closed). ساعت = ساعت PHP (`now()`، برای تست قطعی)، نه `TIME` ردیس. ⚠ **معنای «۹۰ در دقیقه»:** ظرفیت Bucket برابر ۹۰ است، پس بعد از یک دقیقه بیکاری تا ۹۰ درخواست Burst مجاز است و در حالت پایدار ۹۰/دقیقه؛ در بدترین پنجره‌ی لغزان یک‌دقیقه‌ای تا ~۱۸۰. اگر Woo/هاست سخت‌گیرتر بود، ظرفیت را با یک کلید Config جدید کوچک کنید (PRD کلیدی برایش ندارد و اضافه نشد).
- **Config (`config/woo.php`):** `base_url/key/secret` از `.env` (`WOO_BASE_URL`، `WOO_CONSUMER_KEY`، `WOO_CONSUMER_SECRET` — همان نام‌های `verify-woo.php`؛ در `.env.example` خالی)، `version=wc/v3`، `timeout=30`، `per_page=50`، `max_retries=4`، `retry_backoff_seconds` (کلید جدید نسبت به لیست PRD تا اعداد جادویی در کد نباشد)، `rate_limit_per_minute=90`، `overlap_minutes=10`. `webhook_secret/webhook_allowed_ips` با P2-09 می‌آید.
- **زیرساخت تست:** `phpunit.xml` حالا `REDIS_DB=15` و `REDIS_PREFIX=heymode-testing-` می‌دهد تا تست‌های Limiter روی Redis واقعی هرگز داده‌ی توسعه را لمس نکنند. **Redis باید در حال اجرا باشد** (روی مک: `redis-server --daemonize yes`، بالای Setup ثبت‌شده در P0-02)؛ نبودنش تست‌ها را قرمز می‌کند نه Skip. `Http::preventStrayRequests()` تضمین می‌کند هیچ تستی به Woo واقعی نمی‌رسد. `FakeWooClient` و Fixtureها = P2-02.
- ⚠ **فرض‌های تأییدنشده روی Woo زنده (باید در P2-05/P2-06 و GATE 1 دیده شود):** (۱) `dates_are_gmt=true` همراه `modified_*` برای مقایسه با ستون GMT (طبق مستندات Woo؛ بدون آن مقدار با ساعت محلی فروشگاه مقایسه می‌شود). (۲) پشتیبانی `orderby=modified` و `modified_after/before` برای هر Endpoint (Categories احتمالاً اصلاً Window ندارد؛ در محصولات هم Enum مرتب‌سازی باید بررسی شود) — Window اختیاری است و Caller می‌تواند بدون آن صفحه‌بندی کند. (۳) `Retry-After` عمداً سقف ندارد (PRD: «honor»)؛ مقدار بسیار بزرگ Worker را همان‌قدر نگه می‌دارد.
- تست: `HttpWooClientTest` (۶۷)، `RedisTokenBucketTest` (۱۱)، `SyncWindowTest` (۸) = ۸۶ تست. با کاشت عمدی سه نقض (۴۰۴ قابل‌تکرار، نادیده‌گرفتن Retry-After، جابه‌جایی `modified_before`) تست‌ها شکستند و پس از بازگرداندن همه سبز شدند. Suite: ۵۵۸ سبز.

## P2-02 — FakeWooClient + Recorded Fixtures

فقط زیرساخت تست (CLAUDE.md §8: «Tests never hit the network. Use FakeWooClient»)؛ هیچ Migration، Model، DTO، Mapper، Job یا Service همگام‌سازی ساخته نشد و رفتار Production عوض نشد (Binding `WooClient → HttpWooClient` دست‌نخورده؛ تست تضمین می‌کند اپ هیچ‌جا `FakeWooClient` را Bind/ارجاع نمی‌دهد).

- **`Services\FakeWooClient`** همان قرارداد `WooClient` (`page`/`pages` با امضای یکسان، تست‌شده با Reflection) به‌علاوه `requests()` برای Assert روی آنچه کد تحت‌تست خواسته (شامل `SyncWindow`). فقط خواندنی است. **بازپخش (Replay) است، نه HttpWooClient دوم:** Retry، Retry-After، Rate limit و Redis در آن نیست؛ Fixture خودش ثبت می‌کند HttpWooClient چند تلاش کرده (`attempts`: ۱ برای خطای خاتمه‌ای، ۵ برای قابل‌تکرارِ تمام‌شده) و Fake همان نتیجه را به‌صورت همان Page/Exception برمی‌گرداند.
- **Fixture = یک مبادله‌ی ثبت‌شده:** `tests/fixtures/woo/**.json` (پوشه‌ها Endpoint را منعکس می‌کنند) با شکل `{request:{endpoint,page,query}, response:{status,headers,body,attempts?}}`؛ ۱۳ فایل: Orders (۲ صفحه + فیلتر `status=completed`)، Products (صفحه‌ی آخر **خالی** با Header صفحه‌ی اول = حذف رکورد بین دو صفحه)، Categories، مجموعه‌ی خالی (TotalPages 0)، و خطاها: ۴۰۱، ۴۰۴، ۵۰۳ و ۴۲۹ تمام‌شده، پاسخ مخدوش (HTML با ۲۰۰، لیست بدون `X-WP-TotalPages`). `WooFixture` (Support) تعریف را اعتبارسنجی می‌کند (Endpoint نسبی، صفحه ≥ ۱، وضعیت HTTP، Header/Query اسکالر) و `FakeWooClient` دو Fixture برای یک درخواست را رد می‌کند (`LogicException`) تا انتخاب بی‌صدا نشود. بارگذاری از دیسک فقط در `tests/Support/WooFixtures` است، نه در `app/`.
- **تطبیق درخواست:** Endpoint + صفحه + فیلترهای اضافی (مقادیر به رشته و بر اساس کلید مرتب). **Window در کلید نیست** (به ساعت وابسته است و Fixture ثابت هرگز دوبار Match نمی‌شد)؛ فقط ثبت می‌شود. درخواست بی‌Fixture → `WooFixtureNotFoundException` با پیام شامل Endpoint/صفحه و آنچه ثبت شده، **فقط نام کلید فیلترها نه مقدارشان**. این Exception عمداً `LogicException` است، نه `WooException`، تا کد Sync که `WooException` را برای Retry/Log می‌گیرد اشتباه تست را قورت ندهد.
- **جلوگیری از انحراف (Drift) بدون دست‌زدن به P2-01:** `FakeWooClientParityTest` هر ۱۳ مبادله را هم از `HttpWooClient` (روی مرز `Http::fake`، بدون شبکه) و هم از Fake رد می‌کند و نتیجه (Page یا Status/Attempts/Retryable/Code/کلاس Exception) باید یکسان باشد. منطق دسته‌بندی وضعیت در Fake عمداً کپی کوچکی از HttpWooClient است (لیست قابل‌تکرار + اعتبارسنجی بدنه)؛ این تست تنها نگهبان آن است — اگر قانون P2-01 عوض شد باید Fake/Fixture هم عوض شود. Mutation check: حذف ۵۰۳ از لیست قابل‌تکرار ← ۲ تست قرمز؛ برگرداندن صفحه‌ی خالی به‌جای خطا ← ۶؛ فراخوانی Http داخل Fake ← ۲۸؛ درز مقدار فیلتر در پیام ← ۱.
- **ایزوله‌سازی:** تست‌های Fake در `tests/Unit` هستند که Laravel را بوت نمی‌کنند (نه Container، نه DB، نه Redis، نه Config)؛ اسکن Token-محور هم تضمین می‌کند `FakeWooClient`/`WooFixture` به `Illuminate\`، Guzzle، `Http/Redis/DB/Cache::`، `config()/now()/app()`، Randomness و فعل‌های نوشتنی ارجاع ندارند. تست Feature هم ثابت می‌کند بعد از `instance(WooClient::class, …)` هیچ Query، کلید Redis یا درخواست HTTP رخ نمی‌دهد.
- ⚠ **Fixtureها «ثبت‌شده» از نظر شکل‌اند، نه ضبط‌شده از Woo زنده:** داده ساختگی و دست‌نویس است (موبایل‌های `0900…` که با `PhoneNormalizer` به `989000…` می‌روند، ایمیل خالی، بدون هیچ اعتبارنامه‌ای؛ تست Hygiene این‌ها را قفل می‌کند). شکل Order (مبالغ رشته‌ای، `currency=IRT`، `variation_id`/`product_id` صفر برای محصول حذف‌شده، موبایل با ارقام فارسی و علامت RTL) بر اساس یافته‌های P0-00 است و روی فروشگاه زنده Diff نشده. در P2-03/P2-05/P2-06 و GATE 1 باید با پاسخ واقعی (ناشناس‌شده) مقایسه شود.
- ⚠ **پوشش عمداً نساخته‌شده:** خطای اتصال/Timeout (`status = null`) Fixture ندارد — نیازی از Backlog نیامده؛ هر وقت یک تست Sync لازم داشت، اضافه‌ی کوچکی روی `WooFixture/replay` است. Endpoint `refunds`/`variations` موفق و `customers` موفق ثبت نشده (P2-05..P2-07 خودشان اضافه می‌کنند).
- **شاخه:** P2-01 پیش‌تر روی `main` (و Origin) بود و شاخه‌ی `sprint/2-sync` محلی وجود نداشت؛ طبق CLAUDE.md §9 (ادغام در main فقط پس از قبولی Sprint) شاخه‌ی `sprint/2-sync` دوباره از HEAD فعلی `main` (`e628b61`) ساخته شد.
- تست: `FakeWooClientTest` (۴۷، Unit) و `FakeWooClientParityTest` (۱۶، Feature) = ۶۳. Suite: ۶۲۱ سبز؛ Pint/PHPStan سبز.
