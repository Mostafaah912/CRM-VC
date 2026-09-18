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
