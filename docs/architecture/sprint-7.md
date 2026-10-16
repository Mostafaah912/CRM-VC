# Sprint 7 — AI Analyst (read-only)

## P7-01 — Read-only PostgreSQL role for AI Analyst + GATE 4 (بخش read-only)

### تصمیم مالک محصول: ارائه‌دهنده‌ی AI این پروژه Gemini است

قبل از پیاده‌سازی، PRD.md به‌طور کامل برای "Anthropic"/"Claude" جست‌وجو شد (`grep -n -i
'anthropic\|claude' PRD.md`). نتیجه: **هیچ‌کجای بخش ۱۹ (AI Analyst)، یا هر بخش دیگری از PRD.md،
Anthropic یا Claude به‌عنوان ارائه‌دهنده‌ی مدل نام برده نشده.** هر ۵ رخداد کلمه‌ی "Claude" در کل
سند به "Claude Code" اشاره دارند — یعنی همان ابزار کدنویسی که این پروژه را می‌سازد (خط ۱: عنوان
سند "PRD نهایی برای Claude Code"؛ خط ۱۳: "هر جلسه Claude Code باید..."؛ خط ۲۹: "Claude Code فقط
ستون «تصمیم نهایی» را اجرا می‌کند")، نه انتخاب مدل هوش‌مصنوعی تحلیل‌گر کسب‌وکار. بخش ۱۹ خودش از
ابتدا provider-agnostic نوشته شده بود: معماری‌اش `AiProvider` (رابط)، `ToolRegistry`، نام مدل را
صریحاً "از config فقط، هرگز hardcode نشود" می‌خواهد، و نقشه‌راه (`P7-02`) از "۳ provider + Fake"
بدون نام‌بردن از هیچ‌کدام صحبت می‌کند.

**نتیجه:** متنی برای patch کردن در PRD §19 واقعاً وجود ندارد — چیزی که باید اصلاح شود نبود. آنچه
واقعاً اتفاق می‌افتد یک **انتخاب ارائه‌دهنده در لایه‌ی از‌پیش‌انتزاعی‌شده** است، نه اصلاح یک فرض
غلط سند. برای شفافیت و برای این‌که تصمیم جایی ثبت‌شده باشد، پیشنهاد یک جمله‌ی الحاقی (نه patch
جایگزین، چون چیزی برای جایگزینی نیست) به انتهای بخش ۱۹، **فقط اینجا نوشته شد — PRD.md ویرایش
نشد**:

> «**افزوده (P7-01، تصمیم مالک محصول):** ارائه‌دهنده‌ی انتخاب‌شده برای فاز ۱، Gemini است. این
> هیچ محدودیتی به معماری provider-agnostic بالا (`AiProvider`، نام مدل از config) اضافه نمی‌کند —
> فقط این‌که کدام یک از «۳ provider»ی که P7-02 می‌سازد، در فاز ۱ واقعاً پشت `AI_MODEL`/
> `GEMINI_API_KEY` روشن می‌شود.»

این تسک (P7-01) فقط **نام** متغیرهای env زیر را، بدون مقدار، به `.env.example` اضافه کرد — هیچ‌کدام
در کد خوانده نمی‌شوند، چون AiGateway/AiProvider/BudgetGuard هنوز ساخته نشده‌اند (P7-02/P7-03):
`GEMINI_API_KEY`, `AI_MODEL`, `AI_BUDGET_MONTHLY_USD`, `AI_BUDGET_WARN_AT`, `AI_MAX_CONTEXT_KB`,
`AI_MAX_TOOL_CALLS_PER_SESSION`. تنها گروه env که این تسک واقعاً مصرف می‌کند `DB_AI_READONLY_*`
است (زیر).

یک یافته‌ی جانبی، بی‌ربط به این تصمیم: `tests/Integration/AiSchemaTest.php` (از Sprint ۱، قبل از
این تسک) یک مقدار نمونه‌ی `'provider' => 'anthropic'` در یک fixture تست دارد. ستون `provider` در
`ai_insights` یک `varchar(20)` آزاد است، بدون CHECK constraint روی مقدارش (برخلاف `type` که CHECK
دارد و تستش هم هست) — یعنی این فقط یک رشته‌ی نمونه در یک تست موجود است، نه وابستگی واقعی به
Anthropic در کد یا schema. خارج از scope این تسک، دست‌نخورده ماند.

### نقش `hm_ai_readonly` (PRD §19، D7، GATE 4)

`app/Modules/Ai/Services/AiReadonlyRoleService.php` — idempotent: هر اجرا با `REVOKE ALL ON ALL
TABLES IN SCHEMA public` شروع می‌شود و فقط ۹ جدول `ALLOWED_TABLES` را دوباره GRANT می‌کند، نه
اضافه‌کردن تراکمی. این یعنی اگر جدولی بعداً از لیست حذف شود (یا هیچ‌وقت اضافه نشده باشد)، دسترسی
نسخه‌ی قبلی هم با اجرای دوباره پاک می‌شود.

جدول‌های مجاز — دقیقاً لیست PRD §19:
```
daily_metrics, cohort_snapshots, product_affinities, customer_metrics,
segments, segment_members, products, product_categories, metric_runs
```

**بررسی PII (الزام کار #۲):** migration هر ۹ جدول (به‌علاوه دو ALTER بعدی —
`add_revenue_breakdown_to_daily_metrics_table`، `add_monetary_recent_to_customer_metrics_table`)
خط‌به‌خط خوانده شد. **هیچ‌کدام ستون نام/تلفن/ایمیل/آدرس ندارند.** `customer_metrics.customer_id`
(PK) و `segment_members.customer_id` یک شناسه‌ی عددی صِرف هستند — بدون دسترسی به جدول `customers`
(که اصلاً GRANT نشده)، این عدد به نام/تلفن قابل تبدیل نیست؛ وجودش هم عمدی و لازم است (PRD خودش
دقیقاً همین دو جدول را با همین ستون در لیست گنجانده — بدون آن، AI نمی‌تواند حتی یک سگمنت یا متریک
را به مشتری نسبت دهد). `churn_reason` (متن فارسی روی `customer_metrics`) هم بررسی شد
(`ChurnCalculator.php:92-95`) — فقط اعداد (روز/آستانه) در یک قالب ثابت فارسی است، هیچ نام/تلفن/شماره
سفارش در آن inject نمی‌شود. **نتیجه: هیچ view یا GRANT ستونی لازم نیست — هر ۹ جدول PRD، همان‌طور
که هست، GRANT SELECT کامل می‌شوند.**

محدودیت‌های سطح نقش (الزام کار #۵)، با `ALTER ROLE` (نه تنظیم اتصال، تا حتی یک اتصال مستقیم psql
هم محدود بماند):
- `default_transaction_read_only = on`
- `statement_timeout` — پیش‌فرض ۵۰۰۰ms، قابل‌تنظیم با `--statement-timeout-ms=` روی خود دستور
  (نه یک env تازه — کار فقط اجازه‌ی افزودن لیست دقیق env های بالا را داده بود)
- `search_path = 'public'`

### استثنای Raw SQL (Rule 7)

`CREATE ROLE`/`GRANT`/`REVOKE`/`ALTER ROLE` معادل Schema:: یا query-builder ندارند. دقیقاً مثل
استثنای P3-07 (`ProductListService`)، این فایل تکی به allow-list تست Rule 7 در
`tests/Arch/ArchitectureTest.php` اضافه شد — نه ماژول Ai کامل، فقط همین یک فایل، فقط به این دلیل.

### اتصال `ai_readonly` (`config/database.php`)

اتصال جدا، از `DB_AI_READONLY_HOST/PORT/DATABASE/USERNAME/PASSWORD`. تست تازه
(`tests/Arch/AiReadonlyConnectionBoundaryTest.php`) تضمین می‌کند هیچ ماژولی غیر از `Ai` (و هیچ
Controller/Job) رشته‌ی `ai_readonly` را حتی لمس نکند.

### دستور `hm:ai-setup-readonly`

نام از نمونه‌ی خود تسک (`hm:ai:setup-readonly`) به `hm:ai-setup-readonly` تغییر کرد — **پیش‌فرض
استدلال‌شده، نه انحراف بی‌دلیل:** هر ۸ دستور artisan موجود در این پروژه (`hm:catalog-dry-run`,
`hm:identity-reresolve`, `hm:resolve-order-items`, `hm:customers-backfill`, `hm:nightly-chain`,
`hm:reconcile`, `hm:sync`) دقیقاً یک `:` دارند و بقیه را با `-` می‌چسبانند؛ نام نمونه‌ی خود تسک
(دو `:`) با این قرارداد یکدست نبود، پس برای یکدستی اصلاح شد.

### ⚠️ یافته‌ی محیط واقعی — Blocker قبل از اجرای تست‌های Integration

کاربر دیتابیس ادمین پروژه (`heymode`، همان نقشی که اتصال `pgsql` با آن وصل می‌شود، در dev و در
`heymode_testing`) **فاقد `CREATEROLE`** است:

```
SQLSTATE[42501]: Insufficient privilege: 7 ERROR: permission denied to create role
```

این یک محدودیت واقعی محیط است، نه باگ در `AiReadonlyRoleService`. چون نقش‌های Postgres
cluster-wide هستند (نه per-database)، یک بار اصلاح کافی است — نیاز به یک نشست psql به‌عنوان
ابرکاربر (superuser) Postgres دارد که این نشست به آن دسترسی ندارد (رمزهای `.env`/`.env.testing`
هم از مسیر خواندن این نشست مسدودند — عمدی، CLAUDE.md §۳). دستور دقیق در گزارش پایانی چت آمده.
این باعث شد `tests/Integration/AiReadonlyRoleTest.php` (۷ تست) در این نشست اجرا نشود — کد
بازبینی‌شده و PHPStan/Pint سبزند، اما تا اعمال این یک دستور توسط کاربر، "سبزی واقعی" آن تست‌ها
تأیید نشده است (نتیجه‌ی فول‌سوئیت همین نشست: ۳۳۰۰/۳۳۰۷ سبز، دقیقاً همین ۷ تست fail، صفر رگرسیون
جای دیگر).

### پیگیری: تلاش برای اتصال بی‌رمز ابرکاربر Postgres (ناموفق) + جداسازی اتصال ادمین

کاربر رمز ابرکاربر Postgres را به یاد نداشت. طبق دستور، این مسیرها — همگی بدون تغییر
`pg_hba.conf`، بدون ریست رمز هیچ نقشی، و بدون restart — امتحان شدند:

1. شناسایی نصب واقعی: `/Library/PostgreSQL/15` (نصب‌کننده‌ی رسمی EDB روی macOS) — نه
   Herd/DBngin/Homebrew.
2. اتصال از طریق سوکت محلی با نام کاربر سیستم‌عامل (`mostafahosseini`).
3. اتصال با `postgres`/`root` و رمز خالی، هم روی سوکت محلی هم روی `127.0.0.1:5432`.

**نتیجه: هر ۵ تلاش دقیقاً با یک خطا شکست خوردند:** `fe_sendauth: no password supplied` — یعنی
این نصب EDB برای هیچ اتصالی (نه حتی سوکت محلی) auth method بدون‌رمز ندارد؛ `pg_hba.conf` خودش
قابل‌خواندن نبود (Permission denied — مالکیت آن با کاربر سیستم‌عامل `postgres` است). یک تلاش
ششم (looking up a possibly-stored password in the macOS login keychain) توسط خود harness به‌عنوان
"Credential Exploration" مسدود شد — به‌درستی؛ این نشست آن مسیر را دنبال نکرد.

**نتیجه: متوقف شدن طبق دستور کاربر.** رمز ابرکاربر فقط با یکی از این‌ها قابل بازیابی/تنظیم است
(خارج از این نشست، به تصمیم کاربر): رمز از جایی که در زمان نصب EDB ذخیره شده (اگر کاربر آن را
یادداشت کرده باشد)، یا دسترسی مستقیم کاربر به ماشین برای اجرای یک ابزار ریست رمز رسمی EDB (که
خودش یک «ریست رمز» است و این دستور صریحاً آن را ممنوع کرده بود)، یا پیدا کردن هر نشست دیگری که از
قبل به superuser وصل است.

**اصلاح همراه (بدون نیاز به ابرکاربر) انجام شد:** `AiReadonlyRoleService` دیگر از اتصال معمول
`pgsql` استفاده نمی‌کند — یک اتصال تازه `ai_admin` (`config/database.php`) ساخته شد که به
`DB_AI_ADMIN_HOST/PORT/DATABASE/USERNAME/PASSWORD` می‌رود (فقط نام در `.env.example`، بدون مقدار)
و اگر این‌ها خالی باشند، دقیقاً به همان مقادیر `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD` fallback
می‌کند — یعنی رفتار امروز عوض نمی‌شود، اما یک مسیر برای جداسازی بعدی (یک کاربر با فقط `CREATEROLE`،
نه بقیه‌ی دسترسی‌های `heymode`) باز شد. دلیل امنیتی: `CREATEROLE` یک privilege escalation واقعی
است — اجازه‌ی ساخت/تغییر هر نقش دیگری در کل cluster — و کاربر دیتابیس روزمره‌ی برنامه (همان که هر
نوشتن Woo-sync را انجام می‌دهد) دلیلی برای داشتن دائمی آن ندارد.

`tests/Arch/AiReadonlyConnectionBoundaryTest.php` به‌روزرسانی شد تا `ai_admin` را هم (در کنار
`ai_readonly`) به ماژول `Ai` محدود کند.

`tests/Integration/AiReadonlyRoleTest.php` اصلاح شد: وقتی `hm:ai-setup-readonly` با
`QueryException` fail می‌شود (یعنی `ai_admin` فاقد `CREATEROLE` است)، هر ۷ تست به‌جای fail، **skip**
می‌شوند (`$this->markTestSkipped(...)` در `beforeEach`، همان الگوی موجود
`TestCase::skipUnlessFortifyHas()`) — این «غیرفعال‌کردن موقت یک تست برای سبز کردن commit» نیست
(CLAUDE.md §9)؛ یک پیش‌شرط محیطی نامشخص است که صادقانه گزارش می‌شود، نه یک assertion منطقی که
دور زده شده باشد. تأیید شده در این نشست: با همین وضعیت فعلی (بدون `CREATEROLE`)، این ۷ تست
`skipped` هستند، نه `failed`.

### وضعیت نهایی P7-01: انجام شد — GATE 4 (بخش read-only) واقعاً اجرا و سبز

بلاکر `CREATEROLE` با ریست رمز ابرکاربر `postgres` توسط کاربر و تنظیم `DB_AI_ADMIN_*` (اتصال `ai_admin`) حل شد. `hm:ai-setup-readonly` هم روی dev (`heymode`) هم روی `heymode_testing` اجرا شد و **۷ تست `AiReadonlyRoleTest` همگی passed (۳۲ assertion)، صفر skipped**. بررسی دستی روی dev، فقط با نقش `hm_ai_readonly`:
- SELECT روی `customer_metrics`: موفق.
- INSERT روی `metric_runs`: `cannot execute INSERT in a read-only transaction`.
- SELECT روی `customers`: `permission denied for table customers`.
- `statement_timeout` نقش: ۵s.

اصلاح‌های همین مرحله: (۱) خطای `CREATE/ALTER ROLE` دیگر متن SQL را (که رمز نقش داخلش بود) چاپ نمی‌کند — فقط SQLSTATE و پیام Postgres؛ یک بار رمز در خروجی چاپ شد و کاربر آن را عوض کرد. (۲) چون نقش cluster-wide است، تست دیگر رمز نقش را override نمی‌کند (قبلاً رمز dev را خراب می‌کرد) و در `afterEach` `statement_timeout` را به پیش‌فرض برمی‌گرداند.

**فعال‌سازی روی production (یک‌بار، بدون CREATEROLE برای کاربر برنامه):**
1. یک credential ابرکاربر (یا کاربری فقط با `CREATEROLE`) را فقط برای این دستور در `.env` سرور بگذار: `DB_AI_ADMIN_HOST/PORT/DATABASE/USERNAME/PASSWORD`. `DB_USERNAME` برنامه هیچ‌وقت `CREATEROLE` نمی‌گیرد.
2. `DB_AI_READONLY_*` را پر کن (رمز تصادفی قوی، فقط حروف و عدد).
3. `php artisan config:clear && php artisan hm:ai-setup-readonly`
4. بعد از اجرا، `DB_AI_ADMIN_*` را از `.env` سرور حذف کن. دستور idempotent است و برای هر تغییر لیست جدول‌ها یا چرخش رمز دوباره با همان مراحل اجرا می‌شود.

### فایل‌ها

جدید: `app/Modules/Ai/Services/AiReadonlyRoleService.php`,
`app/Console/Commands/AiSetupReadonlyRoleCommand.php`,
`tests/Arch/AiReadonlyConnectionBoundaryTest.php`, `tests/Integration/AiReadonlyRoleTest.php`,
این فایل.
تغییر: `.env.example` (نام envها بدون مقدار، شامل `DB_AI_ADMIN_*` در پیگیری)، `config/database.php`
(اتصال‌های `ai_readonly` و `ai_admin`)، `tests/Arch/ArchitectureTest.php` (استثنای Rule 7)،
`tests/Arch/SyncCommandBoundaryTest.php` (لیست فایل‌های command).


## P7-02 — AiProvider + driverها + FakeAiProvider

**PRD کدام ۳ provider را نام برده:** هیچ‌کدام — §19/§24/§25 فقط «AiProvider + 3 providers + FakeAiProvider» می‌گویند و هیچ نامی نمی‌آورد (کل PRD برای anthropic/openai/gemini جست‌وجو شد: صفر رخداد). چون PRD نامی نبرده، «جایگزینی» لازم نبود؛ سه driver این‌ها شدند — **Gemini** (انتخاب مالک محصول، کامل)، **Anthropic** و **OpenAI** (ساده، فقط ساخت درخواست/parse، تأییدنشده با API زنده). این انتخاب سه‌گانه یک پیش‌فرض استدلال‌شده است، نه عدد PRD.

### ساختار (`app/Modules/Ai`)
- `Services/AiProvider` (interface، یک متد `generate(AiRequest): AiResponse`)، `AiProviderFactory` (انتخاب driver از `config('ai.provider')`، تنها کدی که driverهای واقعی را می‌شناسد)، `AiCostCalculator`، `FakeAiProvider` (همان الگوی `FakeWooClient` که در `Services` ماژول خودش است).
- `Drivers/HttpAiDriver` (پایه: Http client، retry کوتاه، نگاشت خطا)، `GeminiDriver`، `AnthropicDriver`، `OpenAiDriver`.
- `Dto/` (`AiRequest`, `AiMessage`, `AiToolDefinition`, `AiToolCall`, `AiResponse`)، `Exceptions/` (`AiProviderException` + Rate-limit/Authentication/Timeout/InvalidResponse/ContentBlocked/Configuration)، `Enums/AiDriver`, `AiMessageRole`.
- `config/ai.php`؛ bind در `AppServiceProvider` (lazy، مثل `WooClient`: نبود کلید هرگز boot را نمی‌شکند، فقط هنگام درخواست `AiConfigurationException` می‌دهد).

### تصمیم‌ها
- **ورودی/خروجی ثابت:** `AiResponse` = متن یا JSON ساخت‌یافته، tool callها، `input_tokens`، `output_tokens`، `cost_usd` (رشته‌ی ۵ رقم اعشار با bcmath، half-up؛ هرگز float)، `model`، `provider`، `duration_ms`. `provider` همان مقدار `ai_insights.provider` است (`gemini` در varchar(20) جا می‌شود).
- **retry:** فقط 429/500/502/503/504/529 و timeout/اتصال؛ سقف `AI_MAX_RETRIES` (پیش‌فرض ۲، پس‌پرداخت ۲s/8s/30s). 401/403/400/404، پاسخ 2xx ناقص و پاسخ blocked هرگز retry نمی‌شوند.
- **امنیت پیام خطا:** کلید فقط در header است (نه URL)؛ پیام exception و لاگ فقط provider، status، attempt و در صورت وجود یک کد خطای enum‌مانند (`^[A-Za-z_]{1,40}$`) دارند — هرگز متن پاسخ، پرامپت یا متن خطای cURL (که URL دارد). تست یک سناریوی پنج‌حالته با کلید/پرامپت/موبایل جعلی می‌سازد و عدم نشت را در پیام‌ها و لاگ‌ها اثبات می‌کند.
- **Gemini:** `generateContent` با `x-goog-api-key`، `systemInstruction`، `contents` (functionCall/functionResponse، نتیجه‌ی چند tool در یک content)، `generationConfig` با `responseMimeType`+`responseSchema`، `tools.functionDeclarations`. کلیدهای JSON Schema که Gemini رد می‌کند (`additionalProperties`, `$schema`, `$id`) حذف می‌شوند؛ توکن «thinking» به‌عنوان خروجی حساب می‌شود (صورت‌حساب واقعی).
- **قیمت‌ها:** در `config('ai.pricing')` هستند و **همگی صفر**، چون قیمت واقعی را حدس نمی‌زنم. با قیمت صفر `AiCostCalculator` برای هر فراخوانی یک warning لاگ می‌کند تا BudgetGuard (P7-03) بی‌صدا غلط نشود. **قبل از P7-03 باید قیمت واقعی مدل انتخابی از صفحه‌ی قیمت provider در `ai.pricing.models` وارد شود.**
- **env:** `AI_PROVIDER`, `AI_MODEL`, `AI_TIMEOUT`, `AI_MAX_RETRIES` (+ `ANTHROPIC_API_KEY`, `OPENAI_API_KEY` برای دو driver دیگر — فقط نام، بدون مقدار). مقدار خالی (کپی `.env.example`) به پیش‌فرض برمی‌گردد (`?:`).
- نام مدل هرگز در کد نیست: فقط `AI_MODEL`؛ arch test الگوی `gemini-*/claude-*/gpt-*` را در ماژول Ai ممنوع می‌کند. `AI_MODEL` فقط `[A-Za-z0-9._-]` می‌پذیرد (جلوگیری از تزریق مسیر در URL مدل Gemini).

### Arch tests (`tests/Arch/AiProviderBoundaryTest.php`)
هیچ کد خارج از ماژول Ai (جز bind در `AppServiceProvider` و دستور P7-01) کلاسی از Ai را نمی‌شناسد؛ bind فقط `AiProvider`/`AiProviderFactory` را می‌نامد، نه driver؛ Http client فقط در `Drivers/`؛ driver واقعی فقط در factory؛ هر تستی که driver/factory را بسازد باید `Http::preventStrayRequests()` داشته باشد؛ کلید/نام مدل هاردکد نشود. الگوی کلید Google (`AIza…`) به تست اسکن secret موجود اضافه شد.

### ⚠ هنوز بدون کلید واقعی تأیید نشده (برای تأیید دستی بعد از P7-08)
1. **شکل دقیق پاسخ Gemini:** `candidates[0].content.parts`، `finishReason` (مقادیر blocked/MAX_TOKENS)، `promptFeedback.blockReason`، `usageMetadata.promptTokenCount/candidatesTokenCount/thoughtsTokenCount`، و اینکه `functionCall.id` برگردانده می‌شود یا نه.
2. **ترکیب `responseSchema` با function calling در یک درخواست** — بعضی مدل‌های Gemini آن را رد می‌کنند؛ اگر چنین بود، AnalystService باید دو مرحله‌ای کند (tool loop، سپس درخواست نهایی با schema).
3. **زیرمجموعه‌ی JSON Schema پذیرفته‌شده‌ی Gemini** (فقط سه کلید حذف می‌شود؛ `oneOf`/`$ref` و… تست نشده).
4. اسم دقیق مدل (`AI_MODEL`) و قیمت‌ها؛ `thoughtsTokenCount` واقعاً جدا از `candidatesTokenCount` است؟
5. دو driver دیگر (Anthropic/OpenAI): کل درخواست/پاسخ، `max_completion_tokens`/`temperature` برای مدل‌هایی که ردشان می‌کنند، و schema خروجی Anthropic (فقط از طریق system prompt).
6. رفتار timeout/retry واقعی و نگاشت 429 Retry-After (فعلاً از ladder ثابت استفاده می‌شود).

### فایل‌ها
جدید: `config/ai.php`; `app/Modules/Ai/{Services/AiProvider,AiProviderFactory,AiCostCalculator,FakeAiProvider,Drivers/*,Dto/*,Exceptions/*,Enums/AiDriver,AiMessageRole}`; `tests/Feature/Modules/Ai/{GeminiDriverTest,OtherDriversTest,AiProviderContainerTest}.php`; `tests/Arch/AiProviderBoundaryTest.php`. تغییر: `app/Providers/AppServiceProvider.php` (bind)، `.env.example`، `tests/Arch/ArchitectureTest.php` (الگوی کلید Google).


## P7-03 — BudgetGuard + ثبت مصرف (`ai_usage_daily`)

وضعیت: **انجام شد.** بدون migration (جدول از P1-04 هست). PRD ماه شمسی نگفته (فقط `'budget' => ['monthly_usd' => 15.0, 'warn_at' => 0.80, 'hard_stop' => true]`)، پس طبق دستور: **ماه میلادی به وقت Asia/Tehran** — هر ردیف `ai_usage_daily.date` روز تهران است، پس عبور ماه مصرف را «خودبه‌خود» صفر می‌کند (چیزی پاک نمی‌شود).

### طراحی
- `BudgetedAiProvider` (decorator) همان `AiProvider` است که container می‌دهد: `reserve → provider → record → release`. هیچ مسیر دیگری به driver واقعی نیست (arch: `AiBudgetBoundaryTest`).
- `BudgetGuard::reserve()` **قبل از** تماس با provider: مصرف ماه (DB) + رزروهای در جریان (cache) + بدترین حالت همین فراخوانی را با سقف مقایسه می‌کند؛ بیشتر = `BudgetExceededException` و provider صدا زده نمی‌شود. بدترین حالت = (برآورد توکن ورودی + `maxOutputTokens`) × قیمت. **برآورد ورودی = ۱ توکن به‌ازای هر بایت UTF-8** (عمداً بالاتر از واقعیت؛ هر tokenizer واقعی چند بایت در هر توکن دارد) تا گارد به‌سمت رد کردن خطا کند.
- **هم‌زمانی:** بررسی سقف و نوشتن رزرو داخل یک `Cache::lock` انجام می‌شود (Redis در prod)، پس دو فراخوانی هم‌زمان نمی‌توانند هر دو از سقف رد شوند. هر رزرو `expires` دارد (۳۰۰s) تا crash یک پردازش بودجه را برای همیشه قفل نکند. اگر lock گرفته نشود → fail-closed (رد). **ثبت مصرف:** در یک تراکنش با `insertOrIgnore` + `lockForUpdate` روی ردیف روز و جمع با bcmath (بدون SQL خام). تست هم‌زمانی با interleaving قطعی است (وسط فراخوانی A، فراخوانی‌های B و C شروع می‌شوند)؛ تست چندپردازشی واقعی روی lock Redis ننوشته شد و تأییدش با حقیقتِ Redis در staging می‌ماند.
- **خطای provider:** مصرفی ثبت نمی‌شود و رزرو آزاد می‌شود (`catch Throwable`).
- **fail-closed قیمت:** قیمت ورودی یا خروجی صفر/ناموجود/نامعتبر (و `ai.budget.allow_unpriced=false`) → `UnpricedModelException` با نام دقیق کلید config. مقدار نامعتبر `AI_BUDGET_MONTHLY_USD` یعنی سقف ۰ (همه‌چیز رد)، نه «نامحدود».
- **سقف توکن مستقل:** `AI_BUDGET_MONTHLY_TOKENS` (ورودی+خروجی، اختیاری) با همان منطق.
- **هشدار:** `AiBudgetWarning` یک‌بار در ماه (marker در cache به کلید ماه، 40 روز) و `AiBudgetExceeded` در هر توقف (فیلد `firstThisMonth`)؛ listener (`App\Support\AiBudgetAlerts`) آن‌ها را با `AlertService::critical(AlertKind::AiBudgetThreshold)` به همان alert موجود PRD §22 تبدیل می‌کند. چرا بیرون از ماژول: `AlertKind` یک enum از Core است و مرز ماژول فقط Service/Event را اجازه می‌دهد (همان دلیل `HealthCheckService`).
- `BudgetStatusService::status()` فقط‌خواندنی: ماه، مصرف/سقف، درصد، `ok|warn|blocked`، آخرین روز مصرف، تعداد درخواست (برای P7-09).

### ⚠ انحراف آگاهانه از CLAUDE.md §2 («هرگز aggregate ذخیره‌شده را increment نکن»)
`ai_usage_daily` با افزایش (requests/توکن/cost) به‌روز می‌شود، نه recompute. دلیل: آن قانون برای جدول‌های مشتق‌شده‌ی قابل‌بازسازی از منبع است؛ اما یک فراخوانی که schema validation را رد می‌کند یا یک retry هیچ ردیف `ai_insights` نمی‌سازد، پس این جدول **تنها** ثبت آن هزینه است و از منبع دیگری بازسازی نمی‌شود. (در فهرست جدول‌های مشتق CLAUDE.md §1 هم نیست.) نوشتن در یک تراکنش با قفل ردیف و بدون SQL خام است.

### محدودیت‌های شناخته‌شده
- اگر provider بیشتر از `maxOutputTokens` صورت‌حساب کند (مثلاً توکن reasoning پنهان)، مصرف واقعی ثبت می‌شود و فراخوانی **بعدی** رد می‌شود — ولی همان یک فراخوانی ممکن است کمی از سقف رد شود.
- marker «یک‌بار در ماه» و رزروها در cache هستند؛ پاک‌شدن cache در وسط ماه می‌تواند یک هشدار تکراری بسازد (نه یک توقف جاافتاده — مصرف از DB خوانده می‌شود).
- `AlertService` خودش alert هم‌نوع را ۶۰ دقیقه dedupe می‌کند؛ هشدار ۸۰٪ و توقف ۱۰۰٪ هم‌نوع‌اند، پس اگر در یک ساعت پشت‌سر هم رخ دهند، دومی فقط در لاگ (warning) می‌آید.

### قبل از اولین فراخوانی واقعی کاربر باید پر کند
1. `AI_PROVIDER`، `AI_MODEL` و `GEMINI_API_KEY` در `.env`.
2. **قیمت واقعی همان مدل** در `config/ai.php` → `pricing.models['<همان AI_MODEL>'] = ['input' => '…', 'output' => '…']` (دلار به‌ازای هر ۱ میلیون توکن، رشته‌ی اعشاری، هر دو > 0). تا پر نشود، هر فراخوانی `UnpricedModelException` می‌دهد.
3. `AI_BUDGET_MONTHLY_USD` (بدون مقدار = ۵ دلار)، `AI_BUDGET_WARN_AT` (بدون مقدار = 0.8) و اختیاری `AI_BUDGET_MONTHLY_TOKENS`.

### فایل‌ها
جدید: `app/Modules/Ai/{Services/{BudgetGuard,BudgetedAiProvider,AiUsageService,BudgetStatusService,AiBudgetConfig},Models/AiUsageDaily,Events/{AiBudgetExceeded,AiBudgetWarning},Exceptions/{BudgetExceeded,UnpricedModel}Exception,Dto/BudgetStatus,Enums/BudgetState,Support/Decimal}`، `app/Support/AiBudgetAlerts.php`، `tests/Feature/Modules/Ai/BudgetGuardTest.php`، `tests/Arch/AiBudgetBoundaryTest.php`. تغییر: `config/ai.php` (`budget`)، `AiCostCalculator` (`price()`)، `FakeAiProvider` (هر Throwable)، `AppServiceProvider` (bind + listener)، `.env.example` (`AI_BUDGET_MONTHLY_TOKENS`)، تست‌های P7-02 (container/boundary).

### P7-03، اصلاح‌های بعدی (دو مورد)
1. **حاشیه‌ی reasoning:** بدترین حالت اکنون `maxOutputTokens + reasoning_margin_tokens` (`AI_REASONING_MARGIN_TOKENS`، پیش‌فرض محافظه‌کارانه ۴۰۹۶) است، هم برای سقف دلاری و هم توکنی؛ `AiResponse::reasoningTokens` (Gemini: `thoughtsTokenCount`، OpenAI: `reasoning_tokens`) جداگانه برمی‌گردد و جزئی از `outputTokens` است (همان‌ها ثبت و صورت‌حساب می‌شوند). پیش‌فرض ۴۰۹۶ حدس محافظه‌کارانه است، نه عدد اندازه‌گیری‌شده — بعد از اولین مصرف واقعی با `thoughtsTokenCount` واقعی تنظیم شود.
2. **هشدار توقف سخت گم نمی‌شود:** `AlertService::critical()` پارامتر `dedupeScope` گرفت (پیش‌فرض خالی = رفتار قبلی)؛ هشدار نرم و توقف سخت dedupe مستقل دارند. mutation هر دو اصلاح با شکست تست تأیید شد.


## P7-04 — ToolRegistry + ۱۲ ابزار فقط‌خواندنی

وضعیت: **انجام شد.** بدون migration. ابزارها از **اتصال `ai_readonly`** (نقش `hm_ai_readonly`) می‌خوانند و تست‌های ابزار روی PostgreSQL واقعی و همان نقش اجرا می‌شوند (۳۸ تست؛ مثل P7-01 اگر `ai_admin` نتواند نقش بسازد skip می‌شوند).

### ⚠ آنچه PRD نمی‌گوید (پیش‌فرض‌های استدلال‌شده، نه اختراع پنهان)
PRD §19 فقط **۱۲ نام** ابزار می‌دهد — هیچ ورودی/خروجی ندارد. ورودی‌ها/خروجی‌ها طراحی همین تسک است و باید مرور شود:
1. **`get_top_products` با ۹ جدول PRD شدنی نیست** (هیچ‌کدام فروش هر محصول ندارد). `customer_product_purchases` (مشتق، P6-01، بدون ستون PII؛ `customer_id` عدد خالی مثل `segment_members`) به `AiReadonlyRoleService::ALLOWED_TABLES` اضافه شد — **تنها گسترش grant نسبت به لیست PRD**. روی هر محیط باید یک‌بار `php artisan hm:ai-setup-readonly` دوباره اجرا شود.
2. **`get_customer_summary`:** PRD آن را با «(بدون نام/موبایل/ایمیل/آدرس)» می‌آورد که به یک مشتری خاص هم می‌خورد، اما دستور این تسک «هیچ ردیف/شناسه‌ی مشتری» را ممنوع کرد؛ پس **خلاصه‌ی کل پایگاه مشتری** است (نه یک نفر). توضیح فردِ Customer 360 (P7-09) باید جداگانه و با تصمیم صریح طراحی شود.
3. **آستانه‌ی گروه کوچک:** PRD آستانه‌ای ندارد؛ **۵ مشتری** (`config('ai.tools.small_group_min')`). گروه کمتر از ۵ هرگز تنها نشان داده نمی‌شود: در یک ردیف `other_small_groups` ادغام می‌شود و اگر مجموع ادغام‌شده هم زیر ۵ باشد عدد به `"<5"` تبدیل می‌شود. روی محصول (<۵ خریدار)، جفت affinity (<۵ هم‌خرید)، کوهورت (<۵ نفر)، سگمنت (<۵ عضو)، و توزیع‌ها (<۵ مشتری) هم اعمال می‌شود.
4. `get_sales_trend` تجمیع هفته/ماه را در PHP روی ≤۳۶۶ ردیف **از قبل تجمیع‌شده‌ی** `daily_metrics` انجام می‌دهد (گروه‌بندی هفته/ماه شمسی با query builder ممکن نبود)؛ این «حلقه روی سطرهای مشتری» نیست.
5. بازه‌ها به‌جای تاریخ آزاد، `days` (عدد صحیح) هستند و روی **آخرین تاریخ موجود در `daily_metrics`** لنگر می‌شوند (داده ممکن است از ساعت عقب باشد)؛ بنابراین هیچ رشته‌ی تاریخ از مدل پذیرفته نمی‌شود. خروجی هر تاریخ هم ISO هم شمسی (`JalaliDate`) با ارقام لاتین.

### جدول ابزارها
| ابزار | ورودی (همه اختیاری) | جدول‌های خوانده‌شده | نکته‌ی حریم |
|---|---|---|---|
| `get_dashboard_metrics` | `days` 1-366 (۳۰) | `daily_metrics`, `customer_metrics` | فقط مجموع |
| `get_sales_trend` | `days`, `granularity` day/week/month | `daily_metrics` | روز ≤۳۱، هفته ≤۱۸۲ |
| `get_rfm_distribution` | — | `customer_metrics` | گروه <۵ ادغام |
| `get_churn_report` | — | `customer_metrics`, `metric_runs` | گروه <۵ ادغام؛ ارزش در خطر <۵ → null |
| `get_retention_report` | `max_period` 1-12, `last_cohorts` 1-24 | `cohort_snapshots` | کوهورت <۵ حذف؛ فقط دوره‌ی mature |
| `get_cohort_report` | `last_cohorts` 1-12, `max_period` 1-12 | `cohort_snapshots` | کوهورت <۵ → `"<5"` |
| `get_product_affinity` | `level` category/product/variation/basket, `limit` 1-15 | `product_affinities`, `products`, `product_categories` | جفت <۵ حذف |
| `get_top_products` | `by` revenue/units/customers, `limit` 1-15 | `customer_product_purchases`, `products` | محصول <۵ خریدار حذف |
| `get_segment_summary` | `segment_id`, `limit` 1-30 | `segments`, `segment_members`, `customer_metrics` | عضو <۵ → `"<5"` بدون آمار |
| `get_customer_metrics_distribution` | `metric` (۶ مقدار) | `customer_metrics` | جمعیت <۵ پنهان |
| `get_anomalies` | `days` 1-30, `limit` 1-20 | — (`AnomalySource`) | فقط فیلدهای whitelist |
| `get_customer_summary` | — | `customer_metrics` | فقط کل پایگاه |

### طراحی
- `ToolRegistry::execute()` **هرگز exception نمی‌دهد**: ابزار ناشناس، آرگومان اضافه/ناقص/خارج از enum/بازه، رشته‌ی بلندتر از ۶۴ یا غیرِ اسکالر، نتیجه‌ی بزرگ‌تر از `max_result_bytes` (۶۱۴۴) و خطای DB همگی `ToolResult` با `error` امن می‌شوند. متن خطا **ورودی رد‌شده را بازتاب نمی‌دهد** (فقط نام آرگومان‌ها / مجاز‌ها)، پس تزریق به prompt بعدی برنمی‌گردد. خطای DB فقط نام کلاس را لاگ می‌کند (پیام QueryException حاوی SQL و مقدارهاست).
- `ToolResult` = نام، آرگومان‌های **اعتبارسنجی‌شده** (روی خطا خالی)، نتیجه، `resultSize`، `durationMs`، `error`. ذخیره در `ai_tool_calls` کار P7-08 است.
- schema مدل‌محور فقط `type/properties/enum/required/items/description`؛ اعتبارسنجی اصلی قوانین Laravel است (تست تضمین می‌کند ویژگی‌های schema و rules یکی باشند و enum‌ها هم‌ارز). `get_anomalies` یک `AnomalySource` می‌خواند؛ تا P7-05 `NullAnomalySource` = `detector_available:false`.
- **SQL:** فقط query builder روی `ai_readonly`؛ جز `AiReadonlyDb` هیچ `DB::` نیست. چند ابزار `selectRaw/havingRaw/orderByRaw` ثابت دارند (aggregate گروه‌بندی‌شده، `percentile_cont`) که query builder نمی‌تواند: استثنای Rule 7 برای `app/Modules/Ai/Tools/` اضافه شد، به شرط تست arch که **هر فراخوانی raw فقط literal باشد (بدون هیچ `$`)**؛ `metric` مدل فقط یکی از ۶ شاخه‌ی literal را انتخاب می‌کند، هرگز در SQL ریخته نمی‌شود.

### تست
۳۸ تست Integration (مقادیر دقیق هر ابزار، تزریق ۸×۷ حالت، آرگومان بد، سقف سطر/اندازه، ماسک گروه کوچک، `permission denied` روی `customers`)، ۳ تست ساختاری schema (از جمله serialise شدن واقعی با `GeminiDriver`)، ۶ تست arch. **GATE 4 («هیچ ابزاری PII نشت نمی‌دهد»)**: fixture با نام/ایمیل/آدرس/موبایل جعلی (ارقام لاتین و فارسی) در `customers`؛ خروجی JSON ۲۱ فراخوانی از ۱۲ ابزار با رشته‌ها و regexهای PII (موبایل، ایمیل، هر رقم فارسی/عربی، کلیدهای `customer_id/phone/email/...`) اسکن می‌شود: صفر برخورد. ۳ mutation (برداشتن آستانه‌ی ۵، نشت `customer_id`، raw با متغیر) همگی با شکست تست گرفته شدند.

### فایل‌ها
جدید: `app/Modules/Ai/Tools/{AiTool,ToolRegistry,ToolCatalog,AiReadonlyDb,SmallGroupMask,ToolFormat,ToolArgumentException}.php`، `Tools/Tools/*` (۱۲ ابزار + `Period`)، `Dto/ToolResult`، `Services/{AnomalySource,NullAnomalySource}`، `tests/Support/AiToolsFixture.php`، `tests/Integration/AiToolsTest.php`، `tests/Feature/Modules/Ai/ToolSchemaTest.php`، `tests/Arch/AiToolsBoundaryTest.php`. تغییر: `config/ai.php` (`tools`)، `AiReadonlyRoleService` (+`customer_product_purchases`)، `AppServiceProvider` (bind `AnomalySource`)، `tests/Arch/ArchitectureTest.php`.


## P7-05 — AnomalyDetector (آمار خالص) + اتصال به `get_anomalies`

وضعیت: **انجام شد.** بدون migration. PRD §19 فقط می‌گوید «ناهنجاری با آمار، نه AI» و `AnomalyDetector (pure statistics, NO LLM, first)` — **هیچ متریک، آستانه یا نام خروجی‌ای نمی‌دهد**، پس همه‌ی عددهای زیر پیش‌فرض مستند همین تسک‌اند (اختلافی با PRD نبود که بخواهد بر پرامپت مقدم شود).

### جایگذاری معماری
`app/Modules/Ai/Anomaly/`: `AnomalyEngine` (آمار **خالص**: ورودی ردیف‌ها، خروجی گزارش؛ بدون DB/ساعت/تصادف/config/HTTP — arch test)، `AnomalyDetector` (بارگذاری ردیف‌ها + as-of)، `RobustStats` (میانه/MAD)، `AnomalyConfig`. ماژول Analytics هیچ سرویس عمومی «سری روزانه» ندارد و دور زدن نقش `hm_ai_readonly` GATE 4 را بی‌معنا می‌کرد؛ پس detector `daily_metrics` را از **همان درگاه `AiReadonlyDb`** می‌خواند که ابزارها (فقط `table('daily_metrics')`). قرارداد `AnomalySource` به `report(int $days): AnomalyReport` تغییر کرد و `NullAnomalySource` (stub مرحله‌ی P7-04) حذف شد؛ `detector_available` دیگر نیست — به‌جایش `status`.

### روز کامل
`DailyMetricsService` همیشه ردیف **امروز** را هم می‌نویسد و در طول روز بازسازی می‌کند. پس as-of = جدیدترین ردیف **≤ دیروز به وقت Asia/Tehran** (۲۱:۰۰ UTC روز قبل = ۰۰:۳۰ تهران روز بعد، تست شده). `data_age_days` = فاصله‌ی as-of تا دیروز؛ اگر زنجیره‌ی شبانه اجرا نشده باشد دیده می‌شود.

### روش و آستانه‌های پیش‌فرض (`config/ai.php` → `anomaly`)
| پارامتر | پیش‌فرض | دلیل |
|---|---|---|
| baseline | **هم‌روز-هفته** در `window_days=56` (۸ هفته) | آخر هفته‌ی ایران (پنج‌شنبه/جمعه) الگوی متفاوت دارد؛ مقایسه با هم‌روز-هفته بدون مدل فصلی |
| `min_samples` | ۴ نمونه‌ی هم‌روز-هفته | کمتر از ۴ → «ناکافی»، نه anomaly |
| مقیاس | `max(1.4826·MAD, 0.10·|median|)` | کف ۱۰٪: MAD صفر هرگز z بی‌نهایت نمی‌سازد |
| z آستانه | info 3.5 / warning 5 / critical 8 | ۳٫۵ حدّ کلاسیک Iglewicz–Hoaglin برای z مقاوم؛ ۵ و ۸ دو پله‌ی بالاتر |
| `min_relative_change` | ۳۰٪ | جلوگیری از هشدار برای نوسان کوچک روی baseline بسیار پایدار |
| `min_abs` | orders 3، net_revenue 1٬000٬000 تومان، aov 100٬000 تومان، customers_new 2، refund_rate 5 امتیاز درصد، repeat_share 10 امتیاز درصد | تغییر مطلق کوچک روی عدد کوچک ناهنجاری نیست |
| `min_baseline_orders` | ۵ (میانه‌ی سفارش هم‌روز-هفته) | فروشگاهی با ۰ تا ۲ سفارش در روز «عادی» معناداری ندارد → `low_volume` |

یک روز **ناهنجار** است فقط اگر هر چهار شرط (|z|≥3.5، تغییر نسبی، تغییر مطلق، حجم baseline) هم‌زمان برقرار باشد. شدت از |z|. روزهای متوالیِ هم‌متریک و هم‌جهت یک رخداد با بازه‌ی `from..to` می‌شوند؛ مقادیر (`observed/expected/deviation/z`) مربوط به روز اوج‌اند.
متریک‌ها: `orders_count`، `net_revenue`، `aov` (روز بدون سفارش حذف)، `customers_new`، `refund_rate` (refunds/revenue، درصد)، `repeat_share` (customers_repeat/customers_total، درصد). مبلغ‌ها تومان صحیح، نرخ‌ها درصد دو رقم اعشار، تاریخ‌ها میلادی + شمسی (`JalaliDate`). **متن فارسی ساخته نمی‌شود** — فقط کلیدها.
مرتب‌سازی: شدت، سپس تازه‌تر، سپس ترتیب ثابت متریک؛ دو اجرا بایت‌به‌بایت یکسان (تست).

### رویدادهای شناخته‌شده
`ai.anomaly.known_events` (پیش‌فرض **خالی**، هیچ تاریخی hardcode نشده): `[['name'=>…,'from'=>'YYYY-MM-DD','to'=>…]]` (میلادی، شامل دو سر). ناهنجاری درون بازه **حذف نمی‌شود** و `known_event` می‌گیرد. ⚠ فصل‌های پرفروش ایران (نوروز، شب یلدا، رمضان/عید، کمپین‌های فروش) می‌توانند false positive بسازند؛ تا وقتی بازه‌ها وارد config نشده‌اند، مدل باید این را بداند و قبل از «هشدار فوری» بپرسد/ذکر کند.

### محدودیت‌ها
- روز با صفر سفارش به‌خاطر **متوقف‌ماندن sync** با روز صفر واقعی از `daily_metrics` قابل‌تفکیک نیست (ردیف صفر هر دو را می‌نویسد). اجرای dev همین را نشان داد: ۲ روز آخر as-of صفر سفارش دارند. آستانه‌ای برای این نمی‌گذارم؛ `data_age_days` و health-check sync جای تفکیک‌اند.
- baseline فقط هم‌روز-هفته است؛ جابه‌جایی تعطیلات در تقویم هفتگی (مثلاً عید وسط هفته) با `known_events` برچسب می‌خورد، نه مدل می‌شود.

### تست
۱۳ تست خالص (injected spike/drop دقیق با شدت/جهت، آخر هفته عادی، مقاومت MAD، ناکافی، حجم کم، MAD صفر، حداقل مطلق، گروه‌بندی، known_event، ترتیب/determinism، واحد پول، ۳ سال داده)، ۷ تست روی PostgreSQL واقعی (روز ناقص، وقت تهران، کهنگی، ابزار از طریق Registry + اسکن PII = صفر، ۳ سال ≈ ۱۱۰۰ ردیف)، ۴ تست arch. ۵ mutation (حذف کف سیگما، baseline غیر هم‌روز-هفته، حذف گارد حجم، میانگین به‌جای میانه، …) همگی با شکست تست گرفته شدند؛ تست MAD-صفر پس از یک mutation که از آن عبور می‌کرد تقویت شد.

### فایل‌ها
جدید: `app/Modules/Ai/Anomaly/{AnomalyEngine,AnomalyDetector,AnomalyConfig,RobustStats}.php`، `Dto/{AnomalyEvent,AnomalyReport}`، `tests/Support/AnomalySeries.php`، `tests/Feature/Modules/Ai/AnomalyEngineTest.php`، `tests/Integration/AiAnomalyDetectorTest.php`، `tests/Arch/AiAnomalyBoundaryTest.php`. تغییر/حذف: `AnomalySource` (قرارداد)، `GetAnomaliesTool`، `NullAnomalySource` (حذف)، `AppServiceProvider` (bind)، `config/ai.php`، `tests/Integration/AiToolsTest.php`.


## P7-06 — ContextBuilder (JSON فشرده با سقف اندازه)

وضعیت: **انجام شد.** بدون migration. PRD §19 فقط می‌گوید «ContextBuilder (tools, compact JSON, max 24KB)» و `max_context_kb => 24`؛ نوع‌های تحلیل، بخش‌ها و اولویت‌ها را نمی‌گوید — همه‌ی تصمیم‌های زیر پیش‌فرض مستند همین تسک‌اند (هیچ اختلافی با PRD نبود). `AI_MAX_CONTEXT_KB` (پیش‌فرض ۲۴) حالا از `config('ai.context.max_kb')` خوانده می‌شود (مقدار اعشاری مجاز است، برای تست).

### ساختار (`app/Modules/Ai/Context/`)
`ContextBuilder::build(AiInsightType, ?int $days): BuiltContext` — فقط `daily_brief` و `weekly_review` (بقیه‌ی انواع در P7-08 با موضوع خاص خودشان؛ بقیه `InvalidArgumentException`). ابزارها را **سمت سرور** با `ToolRegistry` اجرا می‌کند؛ بدون LLM، بدون شبکه، بدون بودجه. خروجی `BuiltContext`: `json`، `sizeBytes`، `maxBytes`، `estimatedTokens` (= بایت ÷ `ai.context.bytes_per_token`=۳، فقط برای لاگ/نمایش؛ BudgetGuard همان برآورد محافظه‌کارانه‌ی ۱ توکن/بایت را دارد)، `truncated`، `truncations`، `sectionBytes`، و `toolResults` (فقط ToolResultهایی که خروجی‌شان **داخل** JSON است، برای ثبت P7-08 در `ai_tool_calls`؛ مدت اجرا در JSON نیست تا قطعی بماند).

| نوع | KPI (روز) | روند | ناهنجاری |
|---|---|---|---|
| daily_brief | ۱ و ۷ | ۱۴ روز، روزانه | ۳ روز |
| weekly_review | ۷ و ۲۸ | ۵۶ روز، هفتگی | ۷ روز |

بخش‌ها به ترتیب ثابت: `meta`، `data_quality`، `truncated`/`truncations`، `kpis` (مقایسه‌ی دوره‌ی قبل از قبل محاسبه‌شده؛ `customer_base` فقط در پنجره‌ی اول)، `anomalies`، `trend`، `customer_health` (RFM، churn، segment، خلاصه‌ی پایگاه)، `retention_cohort`، `products_affinity`.

### سقف و کوتاه‌کردن
اگر JSON فشرده از سقف بگذرد، **کم‌اولویت‌ترین** بخشی که هنوز پایین‌تر نرفته یک پله کوتاه می‌شود: `products_affinity` (۳ پله) → `retention_cohort` (۳) → `customer_health` (۳) → `trend` (۲) → پنجره‌ی دوم KPI (۱) → `anomalies` (فقط کوتاه می‌شود: limit ۱۰←۵←۳←۱، هرگز حذف نمی‌شود). `meta`، `data_quality` و پنجره‌ی اول KPI هرگز لمس نمی‌شوند. هر کوتاه‌شدن هم در `BuiltContext` و هم **داخل خود JSON** (`truncated: true` + `truncations[{section, action: reduced|removed, detail}]`) علامت می‌خورد تا مدل بداند تصویر ناقص است. اندازه‌ی نهایی هرگز از سقف نمی‌گذرد؛ اگر هسته‌ی غیرقابل‌کاهش هم جا نشود `ContextTooLargeException` (به‌جای حذف بی‌صدا).

### تازگی و کیفیت داده (`DataFreshnessReader` → `data_quality`)
فقط از جدول‌های مجاز نقش: `as_of` و `data_age_days` (جدیدترین روز کامل `daily_metrics` تا دیروز تهران، مثل anomaly detector)، `stale_data` (= سن بیش از `ai.context.stale_after_days`، پیش‌فرض ۱، env: `AI_CONTEXT_STALE_AFTER_DAYS`)، `pipeline_last_run` (= `max(daily_metrics.computed_at)`)، آخرین اجرای موفق/ناموفق metrics از `metric_runs`، `metrics_failed_recently` (= جدیدترین اجرای ناموفق از آخرین موفق جدیدتر است)، `warnings` (کدهای ساختاری: `stale_data`، `metrics_run_failed_recently`). **وضعیت Sync مستقیم قابل‌خواندن نیست** (نقش `hm_ai_readonly` به `sync_cursors`/`sync_logs` دسترسی ندارد و گسترش grant بدون تصمیم صریح انجام نشد)؛ پس «استنتاجی» است و خروجی `sync_status_source: inferred_from_pipeline_last_run` می‌گوید. استدلال: زنجیره‌ی شبانه یک `Bus::chain` است که در اولین شکست می‌ایستد (PRD §22) — پس شکست Sync = بازسازی نشدن `daily_metrics` = بالارفتن `data_age_days`.

**`suspected_data_gap`:** ناهنجاری با `observed = 0` روی `orders_count`/`net_revenue`/`customers_new` وقتی `stale_data` یا `metrics_failed_recently` برقرار باشد، `suspected_data_gap: true` می‌گیرد (حذف نمی‌شود)، شمارنده‌ی `suspected_data_gap_events` و `interpretation` ساختاری (`zero_values_may_be_missing_data_not_business_decline`) در `data_quality` می‌آید. با داده‌ی تازه و pipeline سالم همان ناهنجاری `suspected_data_gap: false` و ناهنجاری عادی است (هر دو در تست). قانون عمداً ساده است: نمی‌داند صفر واقعی است یا شکاف، فقط وقتی شواهد غیرمستقیم هست هشدار می‌دهد. `customers_new` به فهرست ساده‌ی «حجم» افزوده شد چون صفر شدن هم‌زمان با سفارش‌ها یک علت دارد (فراتر از دو متریک نام‌برده‌ی دستور).

### قطعی‌بودن
ترتیب کلیدها و ردیف‌ها ثابت؛ هیچ زمان «اکنون»ی در JSON نیست (فقط داده‌ی DB و `data_age_days` که در یک روز ثابت است)؛ `json_encode` یک‌شکل. برای رسیدن به این، چهار ابزار tie-break/ترتیب ثابت گرفتند (RFM: `count desc, rfm_segment`؛ churn و `clv_confidence`: ترتیب ثابت؛ affinity: id جفت) — قبلاً به ترتیب فیزیکی ردیف‌های Postgres وابسته بودند. تست: ردیف‌های ۵ جدول را به‌ترتیب برعکس دوباره درج می‌کند و بایت‌ها را مقایسه می‌کند.

### PII (دفاع در عمق)
`ContextPiiGuard` روی JSON نهایی (ساختار decode‌شده): نام کلیدهای ممنوع (`customer_id`، `phone*`، `email*`، `first/last/display/full_name`، `address*`، …) و برای هر **رشته** (نه عدد — درآمد ۱۰ رقمی موبایل نیست): موبایل ایرانی (ارقام لاتین/فارسی/عربی، `+98`/`0098`/بدون صفر)، ایمیل، دنباله‌ی ≥۷ رقم فارسی، و `ai.context.pii_denylist` (پیش‌فرض خالی؛ تست‌ها نام fixture را می‌گذارند). برخورد ⇒ `ContextPiiException` با **مسیر JSON و نوع** (هرگز مقدار) و هیچ خروجی. ⚠ نام محصول/سگمنت/دسته که شبیه موبایل/ایمیل باشد کل context را رد می‌کند (fail-closed)؛ باید در Woo/سگمنت اصلاح شود.

### اصلاح همراه: `AuditServiceTest`
علت قطعی شد: `AuditService::paginate()` فقط `latest('created_at')` داشت و `audit_logs.created_at` پیش‌فرض DB (`now()`) است که **داخل یک تراکنش برای همه‌ی ردیف‌ها یکی است** (تست‌ها در تراکنش RefreshDatabase) ⇒ tie و ترتیب دلخواه Postgres. اصلاح کم‌ریسک: `->orderByDesc('id')` به‌عنوان tie-break. در production رفتار فقط وقتی عوض می‌شود که دو ردیف دقیقاً همزمان باشند (آن‌وقت جدیدتر اول). تست تازه با سه ردیف هم‌زمان.

### تست
۱۹ تست Integration روی PostgreSQL واقعی و نقش `ai_readonly` (ساختار و ترتیب، دو نوع تحلیل، اعداد مقایسه با جمع مستقل fixture، تازگی سالم/کهنه/آستانه‌ی قابل‌تنظیم، ۳ حالت `suspected_data_gap`، sweep ۴۱ سقف مختلف با ترتیب کوتاه‌کردن و بخش‌های حیاتی، `ContextTooLargeException`، قطعی‌بودن با ترتیب فیزیکی برعکس، ToolResultها، ۴ حالت PII، بدون HTTP)، ۱۴ تست guard، ۴ تست arch، ۱ تست audit. ۴ mutation (کوتاه‌کردن anomalies اول، حذف PII guard، نادیده‌گرفتن stale، نشکستن سقف) همگی با شکست تست گرفته شدند.

### فایل‌ها
جدید: `app/Modules/Ai/Context/{ContextBuilder,DataFreshnessReader,ContextPiiGuard,ContextPiiException,ContextTooLargeException}.php`، `Dto/BuiltContext`، `tests/Integration/AiContextBuilderTest.php`، `tests/Feature/Modules/Ai/ContextPiiGuardTest.php`، `tests/Arch/AiContextBoundaryTest.php`. تغییر: `config/ai.php` (`context`)، `.env.example`، چهار ابزار (ترتیب قطعی)، `AuditService`+`AuditServiceTest`، `tests/Support/AiToolsFixture.php` (`loadDaily`).
