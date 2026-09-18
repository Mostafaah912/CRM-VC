# HeyMode Customer OS — Architecture Log

این فایل حافظه‌ی مشترک پروژه است. با هر تصمیم معماری و هر یافته‌ی راستی‌آزمایی به‌روز می‌شود.

## محیط توسعه (Local Mac) — ثبت‌شده در شروع پروژه

- سیستم: MacBook Pro (MacBookPro12,1) / Intel x86_64 / macOS 12.7.6
- PHP: 8.4 (از طریق Laravel Herd)
- Laravel: نصب‌شده با starter kit — React + Inertia + Laravel built-in auth (با 2FA و Passkeys)
- Node.js: 22.17.0 / npm: 10.9.2
- Composer: نصب‌شده
- PostgreSQL: 15.19  ← توجه: نسخه ۱۵ است، نه ۱۶
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

هنگام تست، `password can be reset with valid token` (یک تست از پیش موجود در استارتر) شکست خورد. علت: `SHOW timezone` روی این اتصال `Asia/Tehran` برمی‌گرداند (تنظیم پیش‌فرض سرور محلی)، نه UTC. Laravel رشته‌های Datetime بدون Offset («Naive») می‌نویسد و فرض می‌کند UTC هستند؛ PostgreSQL چنین رشته‌ای را وقتی وارد ستون `timestamptz` می‌شود با Timezone **نشست** (نه UTC) تفسیر می‌کند. نتیجه: هر مقدار `timestamptz` نوشته‌شده توسط اپ ۳ ساعت و ۳۰ دقیقه جابه‌جا می‌شد — یک باگ بی‌صدا که مستقیماً قانون «Timestamps ذخیره UTC» بخش ۲ CLAUDE.md را نقض می‌کرد و روی *همه* جدول‌های آینده (Orders، Metrics، …) اثر می‌گذاشت، نه فقط جدول‌های این Migration.

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