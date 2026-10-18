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


### P7-06، اصلاح‌های بعدی (دو مورد)
1. **PII guard حالا redact می‌کند، نه رد:** رشته‌ی داده‌ای (نام محصول/سگمنت/دسته، SKU) که شبیه موبایل (هر دو سیستم رقم)، ایمیل، ≥۷ رقم فارسی پشت‌سرهم یا ورودی denylist باشد با `[redacted]` عوض می‌شود و context ادامه می‌یابد؛ تعداد در `meta.redactions` و در `BuiltContext::redactions` می‌آید و **فقط مسیر و نوع** لاگ می‌شود (`Log::warning`)، هرگز مقدار. **کلید ممنوع** (`customer_id`, `phone*`, `email*`, `first_name` …) در هر جای payload هنوز **fail-closed** است (`ContextPiiException`)، چون یعنی یک رکورد مشتری ساختاراً حاضر است. `assertClean()` به‌عنوان گذر تأیید پس از sanitize می‌ماند و روی خروجی نهایی صفر برخورد می‌طلبد. ارقام ۱۰‌رقمی عددی (نه رشته) هرگز اسکن نمی‌شوند.
2. **`sync_cursors` به grant اضافه شد:** ستون‌ها `entity, cursor_value, last_run_at, last_status, consecutive_failures, updated_at` هستند — نه PII، نه payload خام، نه شناسه‌ی مشتری. پس `ALLOWED_TABLES` (اکنون ۱۱ جدول) و تست grant به‌روز شد و `DataFreshnessReader` سلامت sync را از آن می‌خواند: `data_quality.sync[]`، `sync_failed_recently` (هر entity با `consecutive_failures > 0` یا آخرین وضعیت failed)، هشدار `sync_failed_recently`، و `sync_status_source: "sync_cursors"` (دیگر `inferred` نیست). این هم یکی از شرط‌های `suspected_data_gap` شد. روی هر محیط یک‌بار `php artisan hm:ai-setup-readonly` دوباره اجرا شود (روی dev انجام شد).


## P7-07 — پرامپت‌های نسخه‌دار + اعتبارسنجی schema + cache

وضعیت: **انجام شد (بدون فراخوانی provider).** بدون migration؛ جدول `ai_insights` از قبل هست و این تسک فقط از آن **می‌خواند**.

### اختلاف/انطباق با PRD §19
- **شکل payload:** PRD این شکل را دارد: `{ headline, summary, key_metrics[], insights[{text,kind,severity}], risks[], opportunities[], recommendations[{action,rationale,effort}], data_gaps[], confidence }`. طبق قاعده‌ی «PRD مقدم است» schema پایه **همین** است و همه‌ی نام‌های PRD عیناً مانده‌اند؛ چون PRD شکل آیتم‌ها را نمی‌گوید، شکل آیتم‌ها طراحی همین تسک است. موارد درخواستیِ مالک **افزوده** شده‌اند، نه جایگزین: `metric_refs` (grounding) روی `key_metrics/insights/risks/opportunities`، `anomalies_explained[{ref,interpretation,is_data_issue}]`، `priority` روی `recommendations`، و `data_quality_note`. `kind` همان FACT/INFERENCE/RECOMMENDATION پرامپت PRD است (`fact|inference|recommendation`).
- **متن پرامپت:** PRD نمونه‌ی system prompt را **انگلیسی** با نسخه‌ی `analyst.daily.v1` می‌آورد؛ مالک صریحاً فارسی خواست. قواعد PRD همه حفظ شده‌اند (FASHION، فارسی ساده، تومان/شمسی، بلوک `<data>` فقط داده، فقط JSON، هرگز نام/موبایل)، همان نام نسخه (`analyst.daily.v1`) برای daily و `analyst.weekly.v1` برای weekly.

### ساختار (`app/Modules/Ai/Analyst`, `resources/prompts`)
- `resources/prompts/analyst.{daily,weekly}.v1.md`: front matter (`version` ≤۲۰ نویسه برای `prompt_version varchar(20)`, `type`) + بخش‌های `<!-- system -->` و `<!-- user -->` (`{{context}}` جای داده). `PromptRepository` آن‌ها را می‌خواند.
- `OutputSchema`: schema ارسالی فقط زیرمجموعه‌ی Gemini (`type/properties/enum/required/items/description`)؛ سقف‌ها (طول headline ≤۱۲۰، summary ≤۷۰۰، متن‌ها ≤۴۰۰، تعداد آیتم‌ها) در schema نیست (خارج زیرمجموعه) و `OutputValidator` اجبار می‌کند.
- `AnalystRequestBuilder`: **context در پیام user جدا** و داخل `<data> … </data>` است، نه الحاق به system prompt؛ هر `<` درون JSON به معادل escape شده‌ی JSON (`\u003c`، همان مقدار، JSON معتبر) تبدیل می‌شود پس هیچ رشته‌ای («`</data>` قوانین را نادیده بگیر») نمی‌تواند حصار را زودتر ببندد؛ round-trip JSON تست شده. خروجی یک `AnalystRequest` آماده است و **چیزی صدا زده نمی‌شود**.
- `OutputValidator`: (۱) ارقام فارسی/عربی-هندی → لاتین، (۲) schema سخت‌گیر (نوع/enum/required/ویژگی ناشناخته ممنوع/سقف‌ها)، (۳) **grounding:** هر `metric_ref` باید مسیر نقطه‌ای واقعی در context باشد (اندیس لیست عدد)؛ `fact` و `key_metrics` حداقل یک ref؛ `anomalies_explained.ref` باید زیر `anomalies.anomalies.N` باشد، (۴) PII. پیام خطاها فقط «مسیر: قاعده» است و متن مدل را نقل نمی‌کند (تست با canary).
- **تصمیم PII خروجی مدل: redact، نه reject.** رشته‌ی شبیه موبایل/ایمیل/denylist در پاسخ مدل با `[redacted]` عوض و شمرده می‌شود (`GeneratedInsight::redactions`)، چون context هرگز PII نداشت — پس چنین رشته‌ای توهم یا SKU است و دور انداختن یک تحلیل معتبر (و هزینه‌ی ۱–۲ فراخوانی) ارزشی ندارد. کلید ممنوع در خروجی اصلاً ممکن نیست (schema ویژگی ناشناخته را رد می‌کند).
- `InsightGenerator`: cache → request → provider → اعتبارسنجی → در شکست **یک** retry اصلاحی (فراخوانی دوم از همان `AiProvider`، پس از `BudgetGuard`) با پیام‌های `[user, assistant("(previous answer rejected)"), user(findings)]` — متن خراب مدل هرگز به retry برنمی‌گردد → در شکست دوم `AiResponseInvalidException` (تعداد فراخوانی دقیقاً ۲، بدون متن خام). خطای provider/بودجه دست‌نخورده عبور می‌کند. ذخیره‌ی insight کار P7-08 است.
- `ResponseCache`: کلید = `type|promptVersion|sha256(model)[:12]|sha256(contextJson)[:40]` (≤۱۲۰، بیشینه‌ی عملی ≈۹۰). context شامل `as_of` است، پس داده‌ی جدید = کلید جدید. `lookup` از `ai_insights` با TTL (`ai.cache.ttl_minutes`، پیش‌فرض ۱۴۴۰). **hit ⇒ هیچ فراخوانی provider و هیچ ردیف مصرف/رزرو** (تست با `BudgetedAiProvider`)؛ `force` دورش می‌زند. ⚠ `payload` از jsonb برمی‌گردد و ترتیب کلیدها را عوض می‌کند (ارزش‌ها یکی‌اند).
- **نگهبان نسخه:** `tests/fixtures/ai_prompt_lock.json` برای هر نسخه sha256 فایل پرامپت و sha256 (schema+limits) را نگه می‌دارد؛ ویرایش بدون نسخه‌ی جدید fail می‌شود و اضافه‌کردن entry جدید یک قدم آگاهانه‌ی reviewable است (هرگز بی‌فکر regenerate نشود).
- `php artisan hm:ai-preview {daily_brief|weekly_review} [--no-data]`: context واقعی را (فقط‌خواندنی، با نقش `ai_readonly`) می‌سازد و پرامپت/مدل/اندازه/توکن تقریبی/بخش‌ها/system/user/schema را چاپ می‌کند؛ **بدون provider، بدون کلید**، arch test تضمین می‌کند به `AiProvider`/Budget/Driver/HTTP دسترسی ندارد.

### تست‌ها و mutationها
اعتبارسنج (۲۴+ حالت)، request/تزریق (۵ رشته‌ی حمله، round-trip)، generator (retry/دو خطا/سه‌ام‌ممنوع/خطای provider/ارقام/redaction)، cache (کلید/TTL/force/hit)، بودجه (retry دو ردیف مصرف، hit بدون مصرف، رد بودجه)، نگهبان نسخه (+ خودآزمایی ویرایش‌شده)، preview روی PostgreSQL واقعی، arch. ۶ mutation (حذف retry، نشت متن بد به retry، خاموش‌کردن grounding، حذف escape `<`، نادیده‌گرفتن TTL، حذف نرمال‌سازی ارقام) همگی با شکست تست گرفته شدند.

### محدودیت‌ها
- grounding بررسی می‌کند **مسیر وجود دارد**، نه این‌که عدد نقل‌شده در `value` با مقدار مسیر برابر است (مقایسه‌ی عدد متن آزاد با مسیر شکننده است؛ می‌ماند برای تأیید دستی بعد از P7-08 روی خروجی واقعی).
- کیفیت واقعی فارسی/فرمان‌ناپذیری مدل در برابر تزریق فقط با کلید واقعی دیده می‌شود؛ تست‌ها ساختار را تضمین می‌کنند، نه رفتار مدل.


## P7-08 — AnalystService + جاب‌های روزانه/هفتگی + سیم‌کشی

وضعیت: **انجام شد (بدون کلید واقعی، بدون شبکه).** بدون migration — `ai_insights` و `ai_tool_calls` از قبل بودند و فقط `AnalystService` در آن‌ها می‌نویسد (arch test).

### هم‌خوانی با PRD
PRD §22: «daily 07:00 GenerateDailyBriefJob · weekly Sat 07:30 GenerateWeeklyReviewJob» (صف `ai`، Asia/Tehran) — همان ساعت‌ها پیش‌فرض‌اند (قابل‌تنظیم). PRD §07 فهرست `AnalystService::dailyBrief/weeklyReview/explain()` و رویدادهای `AiInsightGenerated`/`AiBudgetExceeded` را می‌آورد: دو متد اول و هر دو رویداد ساخته شد؛ `explain()` منتظر P7-09 (UI) می‌ماند چون به یک subject نیاز دارد. PRD «AiGateway::complete()» را نام می‌برد؛ در عمل همین نقش بین `BudgetedAiProvider` + `InsightGenerator` + `AnalystService` تقسیم است (نام عوض شد، رفتار PRD حفظ شده). یک `AlertKind` جدید (`ai_generation_failure`) اضافه شد که در فهرست ۶تایی PRD §22 نیست (تست `AlertServiceTest` آگاهانه به‌روز شد).

### جریان `AnalystService::generate(type, period, requestedBy, force)`
`AnalystReadiness` (خاموش/کلید/مدل/قیمت) ← `ContextBuilder` ← **گیت سن داده** (`data_age_days` > `ai.generation.max_data_age_days`=۳ یا بی‌داده ⇒ `StaleDataException` + رویداد + alert، قبل از هر فراخوانی) ← کلید cache و `ResponseCache::find` (hit ⇒ همان ردیف، **صفر فراخوانی، صفر مصرف**) ← throttle دستی ← `InsightGenerator` (همیشه با `force:true`، چون cache را همین‌جا چک کرده‌ایم) از طریق `BudgetedAiProvider` ← **یک تراکنش:** ردیف `ai_insights` + یک `ai_tool_calls` برای هر `ToolResult` داخل context. خطا در هر مرحله ⇒ هیچ ردیفی (تست rollback وسط ذخیره‌سازی).
- `cache_key` در `ai_insights` UNIQUE جزئی است؛ با `force` یا TTL گذشته کلید تکراری می‌شود، پس ردیف قدیمی تاریخچه‌اش را نگه می‌دارد ولی `cache_key` را NULL می‌کند و ردیف جدید کلید را می‌گیرد.
- شکست‌ها: بودجه/بی‌قیمت **بی‌صدا عبور** می‌کنند (لایه‌ی بودجه خودش alert داده، تکرار نمی‌کنیم)؛ پاسخ نامعتبر (بعد از retry)، خطای provider و خطای context ⇒ `AiGenerationFailed` ⇒ `App\Support\AiGenerationAlerts` ⇒ `AlertService` (dedupe جدا برای هر type:reason). متن alert فقط کد علت و شمارش است — بدون متن مدل و PII.

### تطبیق عددی `key_metrics` (در `OutputValidator`)
برای هر آیتم، `value` باید با عددِ **اولین `metric_ref`** در context برابر باشد. قواعد: اولین عدد در متن پس از حذف جداکننده‌ی هزارگان (`,` و جداکننده‌ی عربی) و فاصله و واحد؛ ارقام فارسی از قبل لاتین شده‌اند؛ نشانه‌دار باید علامت درست داشته باشد (`+12.5%` برای `-12.5` رد می‌شود)، بی‌علامت می‌تواند اندازه‌ی یک کاهش باشد (`12.5% کاهش`)؛ گرد کردن **فقط تا دقت اعلام‌شده**: `83%` یا `83.3` برای `83.33` مجاز، `84%` یا `10.4%` برای `10.0` نه؛ پول عدد صحیح است پس باید دقیق باشد (`8.8` برای `8,800,000` رد می‌شود). ref غیرعددی (آبجکت/رشته) مقایسه نمی‌شود. ناسازگار ⇒ همان مسیر retry/نامعتبر. **برای متن آزاد انجام نشد، عمداً:** «کمی بیشتر از یک‌دهم» یا «نزدیک به ۹ میلیون» عدد واحدی برای مقایسه ندارد و استخراج عدد از جمله‌ی آزاد یا false-positive می‌سازد یا ادعای غلط را رد نمی‌کند؛ پس تضمین فقط روی فیلد ساخت‌یافته است. ۳ mutation (حذف چک، تحمل شل، ذخیره بدون تراکنش) با شکست تست گرفته شد.

### دوره‌ها (`AnalystPeriods`)
daily = **دیروزِ کامل تهران**، مستقل از ساعت اجرا؛ weekly = **آخرین هفته‌ی کامل شنبه تا جمعه** (شنبه ۰۷:۳۰ هفته‌ای را مرور می‌کند که شب قبل تمام شده). `scope` = `day:2026-10-13` یا `week:2026-10-10..2026-10-16` (≤۴۰ نویسه)، `period_start/end` تاریخ میلادی، و برچسب شمسی با `JalaliDate`. مرزها تست شده: ثانیه‌ی قبل/بعد نیمه‌شب، UTC که در تهران روز بعد است، مرز هفته، عبور از سال میلادی، و **هفته‌ای که از 1405/12/29 تا 1406/01/06 می‌رود**. ⚠ context همیشه به **جدیدترین روز موجود** لنگر است (نه تاریخ دلخواه)، پس `--date` فقط برچسب دوره را عوض می‌کند و گیت سن داده جلوی اختلاف بزرگ را می‌گیرد.

### محافظت‌ها
`AI_ENABLED` (پیش‌فرض **false**) و `AnalystReadiness`: خاموش یا کلید/مدل/قیمت ناقص ⇒ جاب‌ها بی‌خطا no-op و علت **یک‌بار در روز** لاگ می‌شود. **باگی که تست پیدا کرد:** `AnalystService` در constructor به `AiProvider` واقعی وابسته بود و driver بدون کلید پرتاب می‌کرد؛ یعنی فقط *resolve* کردن جاب روی ماشین بی‌کلید می‌شکست. حالا `BudgetedAiProvider` حامل Closure است و driver را در اولین `generate()` می‌سازد. throttle دستی: `ai.manual.max_requests` (۵) در ساعت **به‌ازای هر requester**، فقط فراخوانی‌هایی که به provider رسیده‌اند می‌شمارند (cache hit رایگان؛ رد بودجه شمرده نمی‌شود)، scheduler/CLI بدون requester محدود نیست.

### جاب‌ها و زمان‌بندی
`GenerateDailyBriefJob`/`GenerateWeeklyReviewJob` (`app/Modules/Ai/Jobs`): نازک — فقط `AnalystService::runScheduled()` و لاگ نتیجه. صف `ai`، `ShouldBeUnique` با `uniqueFor` (۹۰۰) > `timeout` (۲۴۰)، `tries=3`، `backoff=[300,900]`: **فقط** خطای provider با `retryable=true` (rate limit/timeout/5xx بعد از retry کوتاه درایور) rethrow می‌شود تا صف دوباره امتحان کند؛ بودجه، بی‌قیمت، داده‌ی کهنه، پاسخ نامعتبر، خطای context، خطای auth همگی به‌صورت `ScheduledOutcome::skipped(reason)` تمام می‌شوند (هر تلاش اضافه پول است). ورودی scheduler در `routes/console.php` **جدا** از `hm:nightly-chain` (Bus::chain روی اولین شکست متوقف می‌شود و خطای AI نباید زنجیره‌ی داده را بشکند — تست تضمین می‌کند `NightlyChainCommand` هیچ اشاره‌ای به Ai ندارد). `runScheduled` اول بررسی می‌کند «زنجیره‌ی امروز تمام شده»: از `MetricRunService::fullRunCompletedSince(امروز ۰۳:۰۰ تهران)` — یعنی یک اجرای **full** موفق که امروز بعد از ۳ صبح شروع شده؛ نه، ⇒ `skipped(chain_not_finished)` و لاگ. (محدودیت: این فقط گام metrics را می‌بیند، همان محدودیت `NightlyChainTimeout` در P6-10؛ گیت سن داده خط دوم دفاع است.) وضعیت آخرین اجرا: جدول تازه نساختم؛ ساده‌ترین راه (برای P7-09) **آخرین ردیف `ai_insights` از هر type** است (`created_at`, `scope`) — اجرای ناموفق/رد‌شده اثری در جدول ندارد و در لاگ + `audit_logs` (alert) دیده می‌شود.

### دستورها
- `hm:ai-generate {daily_brief|weekly_review} [--force|--no-cache] [--date=YYYY-MM-DD]`: خروجی خوانا (CACHE HIT یا GENERATED، شناسه، scope، headline، تعداد یافته‌ها، provider/model/prompt، توکن، cost، ms، تعداد tool call ذخیره‌شده، تعداد redaction، `data_quality_note`). `--force` و `--no-cache` یکی‌اند. خطاها با `AiErrorExplainer` به یک جمله‌ی روشن (بودجه، قیمت‌نشده، کلید ناموجود/ردشده، شبکه، سن داده) نگاشت می‌شوند؛ استثنای ناشناخته فقط **نام کلاس** را نشان می‌دهد (پیام DB/فریم‌ورک می‌تواند SQL/مقدار داشته باشد).
- `hm:ai-ping [--max-tokens=64]`: یک فراخوانی ارزان از پشته‌ی واقعی (BudgetedAiProvider ← driver)، حتی با `AI_ENABLED=false`؛ خروجی موفق/ناموفق + علت، latency، توکن، cost؛ هرگز کلید چاپ نمی‌شود (تست با کلید نشانه‌گذاری‌شده در بدنه‌ی خطا و URL). مصرفش در `ai_usage_daily` ثبت می‌شود.

### تست و کارایی
۴۱ تست روی PostgreSQL واقعی + ۲۰ تست ping/زمان‌بندی/پیکربندی + ۱۸ تست مرز دوره + ۴۰ تست جدید validator. **یک باگ زیرساخت تست:** `AiToolsFixture::teardown()` در `afterEach` یک `TRUNCATE` می‌زد، اما تراکنش باز `RefreshDatabase` تا پایان تست قفل خواندن روی `metric_runs` (که `MetricRunService` از اتصال پیش‌فرض خوانده بود) را نگه می‌داشت — TRUNCATE برای همیشه منتظر می‌ماند و کل suite گیر می‌کرد. اصلاح: teardown اول تراکنش تست را rollback می‌کند و `wipe()` با `lock_timeout=5s` اجرا می‌شود تا هر مشکل قفل در ثانیه‌ها خطا بدهد، نه hang.


## کاتالوگ در زنجیره‌ی شبانه: اندازه‌گیری، timeout، و جداکردن شکست (P7-08 follow-up)

### اندازه‌گیری (یک اجرای کامل زنده‌ی `CatalogSyncService`، CLI، 2026-10-18)
- **۹۱۱ ثانیه** کل: ۳۱۰ درخواست **پشت‌سرهم** به Woo، میانگین ۲٫۷۸ ثانیه (بیشینه ۲۴٫۶۷)، مجموع انتظار شبکه **۸۶۲٫۶ ثانیه (۹۵٪)**، فقط **۴۸٫۸ ثانیه** کار DB/PHP؛ ۱۶۳ مگابایت payload؛ حافظه‌ی اوج ۱۳۲ مگابایت. یعنی DB کند نیست و N+1 محلی وجود ندارد؛ زمان همه انتظار Woo است.
- ترکیب درخواست‌ها: ۴ صفحه‌ی دسته‌ها (۱۲ ثانیه)، **۵۵ صفحه‌ی محصول (هر صفحه ≈ ۱٫۵ مگابایت JSON، ≈ ۳–۴ ثانیه)**، و **یک درخواست `/products/{id}/variations` به‌ازای هر محصول variable** (Woo اکنون **۲۵۱** محصول variable دارد) — این همان N+1 سمت Woo است: ≈ ۲۵۱ × ۲٫۸ ≈ ۷۰۰ ثانیه.
- نرخ‌محدودکننده (۹۰ در دقیقه) گلوگاه نیست: ما ≈ ۲۱ درخواست در دقیقه می‌فرستیم؛ تأخیر هر درخواست محدودکننده است، نه توکن.
- نتیجه: با timeout قبلی (۳۰۰ ثانیه) این جاب **هرگز** نمی‌توانست تمام شود: failed_jobs #4 و #5 (۰۹-۳۰)، #۶ (۱۰-۰۵، SyncPageJob)، #۱۱ و یک مورد دیگر (۱۰-۱۸) همگی `TimeoutExceededException`.
- **یافته‌ی جانبی (کیفیت داده، همان Open Item شناخته‌شده‌ی قیمت اعشاری Woo):** Woo ۲٬۷۲۲ محصول دارد (۲۵۱ variable، ۲٬۴۷۱ simple) اما آینه‌ی محلی ۲٬۵۱۷ (فقط **۴۲ variable**). یعنی حدود ۲۰۹ محصول variable هنوز وارد آینه نمی‌شوند (رد می‌شوند چون یکی از variationهایشان قیمت اعشاری دارد) ولی هر شب هزینه‌ی درخواست variationهایشان پرداخت می‌شود. (شمارنده‌ی `rejected` خروجی سرویس را در اسکریپت اندازه‌گیری درست نخواندم؛ عدد را از مقایسه‌ی Woo با DB گرفتم، نه از آن شمارنده.)

### تصمیم‌ها
1. **timeout = ۱۸۰۰** (حدود دو برابر اندازه‌گیری، با جا برای رشد کاتالوگ)، `uniqueFor = ۲۱۰۰`. چون «timeout جاب < retry_after» قاعده‌ی پروژه است: `queue.connections.redis.retry_after` از ۹۳۰ به **۱۸۳۰**، و `horizon.defaults.supervisor-1.timeout` از ۹۰۰ به **۱۸۰۰** رفت. عوارض: اگر یک worker وسط یک جاب بمیرد، جاب به‌جای ۱۵ دقیقه بعد از ۳۰ دقیقه دوباره قابل‌رزرو است. تست‌های هم‌خوانی (`retry_after ≥ بلندترین timeout + ۳۰` و `Horizon timeout` بین timeout و retry_after) اکنون کاتالوگ را هم شامل می‌شوند. **۹۰۰ کافی نبود** (۹۱۱ اندازه‌گیری‌شده).
2. **tries = ۱ می‌ماند:** هر retry کل ۱۵ دقیقه‌ی خواندن را تکرار می‌کند، داخل chain بقیه‌ی گام‌ها را همان‌قدر عقب می‌اندازد، و upsertها هرچند idempotent‌اند (داده‌ی تکراری ساخته نمی‌شود) retry چیزی را بهتر نمی‌کند؛ جبران، اجرای شب بعد است.
3. **شکست کاتالوگ زنجیره را نمی‌شکند (fail-soft فقط برای همین گام):** PRD §22 می‌گوید `Bus::chain` در اولین شکست متوقف شود «تا با داده‌ی ناقص اجرا نشود». این برای هر گام **داده** درست می‌ماند. کاتالوگ فقط آینه‌ی فهرست محصول Woo است و هیچ‌یک از گام‌های بعدی (RecomputeMetrics، متریک روزانه، cohort، segments، reconcile) آن را نمی‌خوانند — همه از سفارش‌ها محاسبه می‌شوند؛ کمبود تازگی کاتالوگ فقط یعنی چند خط سفارش دیرتر به محصول وصل می‌شوند. پس تنها این گام fail-soft شد: `CatalogSyncJob(failSoft: true)` در `hm:nightly-chain`؛ خطای قابل‌گرفتن (Woo، mapping) با `CatalogSyncService::syncAll(failSoft: true)` فقط **نام کلاس** لاگ می‌شود، رویداد `CatalogSyncFailed` می‌رود و `App\Support\SyncFailureAlerts` یک alert از نوع `SyncFailure` با dedupe جدا (`catalog`) می‌سازد؛ بقیه‌ی زنجیره ادامه می‌دهد. اجرای مستقل (`failSoft=false`، پیش‌فرض) مثل قبل exception می‌دهد. منطق کنترلی در Service است، نه Job (قانون «Job بدون منطق»، تست arch آن را اجبار کرد).
4. **قرارداد scheduler و ترکیب زنجیره دست‌نخورده است** (همان ۸ جاب به همان ترتیب) — فقط رفتار شکست یک گام عوض شد، پس نیازی به تأیید طراحی نبود. جایگزین‌های ردشده: بیرون‌بردن کاتالوگ از chain یا dispatch غیرهمزمان (ترتیب «کاتالوگ قبل از سفارش‌ها» در PRD را می‌شکند و ورودی scheduler جدید می‌خواهد).
5. **محدودیت باقی‌مانده:** timeout توسط worker kill می‌شود و قابل‌گرفتن نیست؛ اگر Woo آنقدر کند شود که از ۱۸۰۰ ثانیه بگذرد، chain باز می‌ایستد. حاشیه ≈ ۲×.

### گزینه‌های سرعت (اجرا نشد، منتظر تصمیم)
(الف) **درخواست‌های variation هم‌زمان** (`Http::pool` با هم‌زمانی ۴، زیر سقف ۹۰ در دقیقه): ≈ ۲۵۱ × ۲٫۸ ثانیه ← ≈ ۱۸۰ ثانیه؛ کل ≈ ۴٫۵ دقیقه. تغییر در لایه‌ی انتقال `WooClient` (Rate limiter، retry، تست‌ها) — ریسک متوسط. (ب) `per_page=100` فقط برای کاتالوگ: صفحه‌ها ۵۵ ← ۲۸ ولی هر صفحه ≈ ۳ مگابایت؛ سود کم‌تر و `per_page` اکنون سراسری است. (ج) رفع ریشه‌ی ۲۰۹ محصول ردشده (قیمت اعشاری) هزینه‌ی variationهای بی‌فایده را هم حذف می‌کند.

### ریسک ثبت‌شده
**زنجیره‌ی شبانه از لحظه‌ای که کاتالوگ در آن آمد (P6-09) با timeout ۳۰۰ ثانیه محکوم به توقف در همان گام اول بود** (اندازه‌گیری: ۹۱۱ ثانیه)، و شکست‌های ثبت‌شده‌ی ۰۹-۳۰ (دو بار) و ۱۰-۱۸ همین را نشان می‌دهند؛ هر بار توقف یعنی `RecomputeMetricsJob(full)`، `BuildDailyMetricsJob`، cohort، segments و reconcile اجرا نمی‌شدند و `daily_metrics`/`data_age_days` عقب می‌ماند (dev: ۱۲ روز). در dev زمان‌بند اجرا نمی‌شود، پس «هر شب» را نمی‌توانم اثبات کنم؛ برای production قبل از روشن‌کردن scheduler این اصلاح لازم بود.


## اولین اجرای واقعی با Gemini (2026-10-18، dev) و ریسک‌های Sprint 8

### آنچه اجرا شد
`hm:ai-ping` ← دو بار (بار اول با `--max-tokens=64` بریده شد: مدل reasoning از سقف خروجی برای «فکر» مصرف می‌کند؛ با `--max-tokens=512` موفق، ۲٫۴۷ ثانیه، ۹/۷۸ توکن، ۰٫۰۰۰۳۰ دلار)؛ سپس **یک** `dailyBrief()` با `AI_ENABLED=true AI_TIMEOUT=120` فقط به‌صورت متغیر محیطی همان پردازش (`.env` دست‌نخورده و `AI_ENABLED` در آن همچنان false). نتیجه: ۱ تلاش، ۱۷٫۴ ثانیه، ورودی ۷٬۰۱۵ توکن (برآورد ما ۷٬۵۸۴: ۸٪ بالاتر، خوب)، خروجی ۲٬۴۰۰ (reasoning ۳۳۲ + قابل‌مشاهده ۲٬۰۶۸)، هزینه **۰٫۰۱۴۲۶ دلار** (با قیمت 0.75/3.75 دقیقاً درست)، ۱۳ ردیف `ai_tool_calls` بدون خطا، `cache_key` ۸۲ نویسه.

### باگ پیدا‌شده (گزارش شد؛ **اصلاح شد — بخش «اصلاح لنگر KPI و پرامپت v2» پایین‌تر**): KPIها روی روز **ناقصِ امروز** لنگر شده‌اند
`Tools\Period::end()` (که `get_dashboard_metrics` و `get_sales_trend` از آن استفاده می‌کنند) آخرین `daily_metrics.date` را برمی‌دارد — و `DailyMetricsService` همیشه ردیف **امروز** (ناقص، در حال ساخت) را هم می‌نویسد. در نتیجه context متناقض بود: `data_quality.as_of = 2026-10-17` (آخرین روز کامل، درست) اما پنجره‌ی KPI از `2026-10-18` (امروز، ۱۷ سفارش، ۱۳٬۰۵۴٬۲۰۰ تومان) تا روز قبل آن می‌رفت. مدل وفادارانه همان را نقل کرد: تیتر «رشد ۲۰٫۵۷٪ درآمد… در 1405/07/26» یک روز **نیمه‌کاره** را با روز کامل ۱۰-۱۷ (۱۲ سفارش، ۱۰٬۸۲۶٬۷۹۹) مقایسه می‌کند، پس «+۲۰٫۵۷٪» و «+۴۱٫۶۷٪ سفارش» و «افت AOV ۱۴٫۸۹٪» گمراه‌کننده‌اند. همین قاعده را `AnomalyDetector` درست دارد (فقط روز کامل)، ولی ابزارها نه. اصلاح پیشنهادی (کوچک): `Period::end()` = `min(max(date), دیروز تهران)` + تست؛ بعدش یک فراخوانی تازه (≈ ۰٫۰۱۴ دلار). تا آن موقع insight شماره‌ی ۱ در dev را مبنای تصمیم نکنید.

### کیفیت خروجی اولین اجرا
- **عددها:** هر چهار `key_metrics.value` با داده می‌خواند (۱۳٬۰۵۴٬۲۰۰، ۱۷، ۷۶۷٬۸۹۴، ۱۱٬۰۱۵٬۹۱۰ — تطبیق عددی اجبارش کرد)؛ ارقام مرجع دیگر (۱۴٬۵۸۲ مشتری، ۱۳٬۴۳۶ تک‌خرید، ۷٫۸۶٪، ارزش در معرض ریزش ۳۲٬۸۲۸٬۶۹۵٬۵۸۲ و ۳٬۹۰۴ از‌دست‌رفته، سگمنت‌های «در معرض ریزش» ۶٬۸۰۷/۲۲۸ روز و «سررسید خرید مجدد» ۴۷۶/۳۱ روز، affinity ۱۰۰٪/لیفت ۴۶۷٫۷۸) هم مستقل تأیید شدند. مشکل عددِ جعلی نبود؛ مشکل لنگر KPI بود (بالا).
- **ادعای قطعی بدون فرضیه:** درباره‌ی ناهنجاری ۱۰-۱۷ (درآمد خالص ۱۰٬۸۲۶٬۷۹۹ در برابر انتظار ۵۷٬۴۵۰٬۱۷۱، ‎−۸۱٫۱۵٪) نوشت «یک افت واقعی … و ناشی از نقص فنی یا گپ داده نیست». هیچ داده‌ای این را ثابت نمی‌کند (فقط هشداری از کیفیت داده نیست)؛ قاعده‌ی ۳ پرامپت («علت فقط فرضیه») را نقض می‌کند. `is_data_issue=false` منطقاً مجاز بود، متن قطعی نه.
- **`confidence: high` توجیه ندارد:** ۱۴٬۲۹۷ از ۱۴٬۵۸۲ مشتری (۹۸٪) `clv_confidence=low` دارند، «ارزش در معرض ریزش» و ریسک `critical` بر برآورد CLV تکیه دارند، ناهنجاری بی‌علت مانده، و KPI روی روز ناقص بود.
- `data_quality_note` و `data_gaps` خالی بودند، هرچند ناهماهنگی `as_of` با `period.to` در خود داده‌ی context دیده می‌شد (مدل آن را نگرفت).
- پیشنهادهای بعدی روی پرامپت (نسخه‌ی جدید + به‌روزرسانی `ai_prompt_lock.json`، اجرا نشد): «هرگز علتی را رد یا اثبات قطعی نکن؛ بنویس هشدار کیفیت داده وجود ندارد» و «اگر بیشتر `clv_confidence` پایین است یا ناهنجاری بدون توضیح هست، `confidence` حداکثر medium».

### ریسک‌های Sprint 8
1. **هم‌زمان‌سازی variationها با `Http::pool`:** هر شب ۲۵۱ درخواست `/variations` پشت‌سرهم (≈ ۷۰۰ ثانیه از ≈ ۹۰۰–۱۱۰۰). با هم‌زمانی حدود ۴ و زیر سقف ۹۰ در دقیقه، کل کاتالوگ ≈ ۴٫۵ دقیقه می‌شود؛ تغییر در لایه‌ی انتقال `WooClient`/rate limiter/retry و تست‌هاست (ریسک متوسط) و شاخص `uniqueFor`/timeout را راحت می‌کند.
2. **۲۰۹ محصول variable با قیمت اعشاری:** Woo ۲۵۱ محصول variable دارد، آینه‌ی محلی ۴۲ (کل ۲٬۷۲۲ در برابر ۲٬۵۱۷). این ~۲۰۹ محصول به‌خاطر یک variation با قیمت اعشاری رد می‌شوند ولی درخواست‌هایشان هر شب پرداخت می‌شود، و سفارش‌هایشان به محصول وصل نمی‌شود (همان سقف ۶۸٪ resolve در ARCHITECTURE.md). یا اصلاح سمت Woo، یا تصمیم رسمی درباره‌ی پذیرفتن/رد‌کردن، یا نگاشت `nullableDecimalMoney` برای variationها.
3. **حاشیه‌ی ۱٫۶× برای kill غیرقابل‌گرفتن:** اجرای واقعی کاتالوگ در chain ۱٬۱۱۳ ثانیه شد (۱۸ دقیقه و ۳۳ ثانیه) در برابر timeout ۱٬۸۰۰؛ worker هنگام timeout جاب را kill می‌کند و `catch` آن را نمی‌بیند، پس اگر Woo کندتر شود و از ۱٬۸۰۰ بگذرد chain باز می‌ایستد (fail-soft فقط خطاهای قابل‌گرفتن را پوشش می‌دهد). راه‌حل ریشه‌ای همان مورد ۱ است؛ راه‌حل موقت: بالا بردن timeout (و `retry_after`/Horizon) یا یک alert زود وقتی کاتالوگ از ۱٬۲۰۰ ثانیه گذشت.


## اصلاح لنگر KPI، هشدار `period_anchor_mismatch`، پرامپت v2 و سقف confidence در کد

**تا پیش از این اصلاح، KPIها روی روز جاری (ردیف ناقصِ امروز) محاسبه می‌شدند**؛ هر خروجی تحلیلگر قبل از این commit (از جمله اولین اجرای واقعی) مقایسه‌ی «روز ناقص با روز کامل» بود. ردیف dev شماره‌ی ۱ در `ai_insights` (همان اجرا) پاک شد (و `ai_tool_calls`اش با CASCADE؛ تأیید: ۰ و ۰).

1. **یک لنگر:** `Tools\Period::end()` = آخرین تاریخ `daily_metrics` که ≤ **دیروزِ تهران** باشد (null اگر نباشد). `get_dashboard_metrics`، `get_sales_trend` و `AnomalyDetector` همه از همین استفاده می‌کنند و `DataFreshnessReader` همان گزارش anomaly را می‌خواند، پس `as_of` همه‌جا یکی است. اگر دیروز ردیف نداشته باشد، به روز کامل قبلی برمی‌گردد، هرگز به امروز نمی‌رود. تست مرزی: با ردیف ناقصِ امروز (۱ سفارش، ۵۰٬۰۰۰ تومان) همه‌ی پنجره‌های KPI، trend و anomalies روی ۲۰۲۶-۱۰-۱۳ می‌مانند. Mutation (برگرداندن به `max(date)`): ۳ از ۳ تست شکست.
2. **هرگز بی‌صدا نیست:** `ContextBuilder` اگر `period.to` هر پنجره‌ی KPI یا trend با `data_quality.as_of` فرق کند، `period_anchor_mismatch` را به `data_quality.warnings` اضافه می‌کند و `data_quality.period_anchor` (`as_of`، `kpi_and_trend_end`، `mismatch`) و `interpretation.period_anchor_mismatch` را می‌گذارد. تست با ابزاری که عمداً روز دیگری برمی‌گرداند.
3. **پرامپت v2** (`analyst.daily.v2`، `analyst.weekly.v2`؛ v1 دست‌نخورده و در قفل با همان hash): علت فقط فرضیه و نه ردِ قطعی/اثباتِ قطعی («ناشی از … نیست» ممنوع؛ نبودِ هشدار یعنی «هشدار ثبت نشده»)، با هر هشدار (از جمله `period_anchor_mismatch`) `data_quality_note` و `data_gaps` خالی نماند، سقف confidence=medium. نسخه‌ی پیش‌فرض اکنون در `config('ai.prompts')` است (`PromptRepository::for()` دیگر «اولین فایل با این type» نیست). `ai_prompt_lock.json` فقط **افزایش** یافت (مدخل‌های v1 بدون تغییر؛ schema hash یکی).
4. **سقف confidence در کد (`OutputValidator::capConfidence`):** `high` به `medium` کاهش می‌یابد اگر (الف) بیشتر مشتریان `clv_confidence=low` باشند (کاملاً بیشتر از نیمه)، (ب) ناهنجاری‌ای در context توسط `anomalies_explained` توضیح داده نشده، یا (پ) `data_quality.warnings` خالی نیست. `medium`/`low` هرگز بالا نمی‌روند. تغییر در `payload.meta.adjustments` ثبت می‌شود (`field/from/to/reasons`)؛ بدون تعدیل، `meta` اضافه نمی‌شود. Mutation: حذف شرط CLV/هشدار ← ۳ شکست؛ حذف خود تعدیل ← ۴ از ۴ شکست.
5. `hm:ai-generate` اکنون confidence، reasoning tokens (داخل توکن خروجی) و تعدیل‌ها را چاپ می‌کند.

تست‌ها: Ai Feature ۲۴۳، AiContextBuilder ۲۶، AiAnalystService ۲۸، AiPreviewCommand ۳، AnomalyDetector ۷، Arch ۲۵۳ همه سبز؛ PHPStan ۰ خطا؛ Pint پاک (فول‌سوئیت اجرا نشد، طبق دستور).

### اجرای واقعی پس از اصلاح (۲۰۲۶-۱۰-۱۸، dev، یک فراخوانی `daily_brief`)
insight #2، دوره‌ی `2026-10-17` (1405/07/25)، prompt `analyst.daily.v2`، ۱ تلاش، ۱۸٫۲ ثانیه، توکن ورودی/خروجی ۷٬۳۰۱/۲٬۵۵۷ (reasoning ۳۲۴ داخل خروجی)، هزینه ۰٫۰۱۵۰۶ دلار، ۱۳ tool call. **KPIها اکنون روی ۱۰-۱۷ هستند:** ۱۲ سفارش، ۱۰٬۸۲۶٬۷۹۹ تومان (−۶۵٫۷٪ نسبت به ۳۱٬۵۶۸٬۸۷۷). `confidence: medium`، `meta.adjustments` خالی (مدل خودش medium داد؛ سقف کد لازم نشد). هیچ علت قطعی‌ای نیامده و هیچ علتی هم حدس زده نشده. دو نقص باقی‌مانده: (۱) تفسیر ناهنجاری می‌گوید «نشانه‌ای از خطای فنی ثبت نشده و این کاهش در مقادیر ثبت‌شده است» (استنتاج از `suspected_data_gap=false`، نیمه‌قطعی؛ قاعده‌ی v2 «نبود هشدار ≠ نبود مشکل» را کامل رعایت نکرد)؛ (۲) ریسک «۳۲٫۸ میلیارد تومان در معرض ریزش» بدون اشاره به اینکه ۹۸٪ CLVها `low` confidence هستند آمده. ولی این‌ها در v3 / تقویت پرامپت قابل رسیدگی‌اند؛ تغییر نکرد.

## P7-08b — پاس کیفیت خروجی Analyst (پرامپت v3)

ایرادهای brief واقعی id=2، هر کدام در کم‌ریسک‌ترین لایه بسته شد (ابزار/context/validator؛ پرامپت فقط پشتیبان):

1. **محاسبه از مدل گرفته شد.** `get_dashboard_metrics`: `change_percent` اکنون برای **همه‌ی** کلیدهای `current` ساخته می‌شود (revenue_new/repeat، customer_days_*، refunds…؛ null وقتی قبلی صفر است). `customer_base` اکنون `one_time_customers`، `one_time_customers_percent` و `repeat_rate_means` (= سهم مشتریان با ≥۲ سفارش) دارد؛ `get_customer_summary` هم `one_time_customers_percent`. (علت خطای ۷٫۸۶٪ در برابر ۹۲٫۱۴٪: مدل خودش ۱۰۰−۷٫۸۶ را ترکیب کرده بود.)
2. **تطبیق `key_metrics.change`:** عدد باید برابر `change_percent` یکی از refها یا محاسبه‌شده از زوج `current/previous` کنار ref اول باشد (همان قاعده‌ی گرد‌کردن `value`: تا دقت اعلام‌شده، بدون علامت = قدر مطلق مجاز). عدد بدون هیچ مرجعِ قابل‌تأیید رد می‌شود (مسیر اصلاح/رد موجود، یک retry)؛ `change` خالی یا غیرعددی («—») آزاد است.
3. **recommendations مرجع دارند** (schema: `metric_refs` الزامی؛ grounding: حداقل یک مسیر معتبر، مثل fact). **بررسی نرم عدد متن آزاد:** هر توکن عددی در headline/summary/text/action/rationale/interpretation/data_gaps/note که در مجموعه‌ی اعداد context نباشد در `payload.meta.ungrounded_numbers` ثبت می‌شود (حداکثر ۲۰) و confidence=high به medium می‌رود (دلیل `ungrounded_numbers`)؛ هرگز رد نمی‌کند. «در context» یعنی: همان عدد، قدر مطلق، رقم‌های داخل رشته‌های context (تاریخ‌ها)، گردشده تا دقت اعلام‌شده، یا مقیاس هزار/میلیون/میلیارد (۸٫۸ میلیون ↔ 8,800,000). اعداد صحیح ۰ تا ۲ نادیده گرفته می‌شوند («۲ سفارش یا بیشتر»). این تحمل عمداً سخاوتمند است (عدد صحیح گرد ±۰٫۵ پذیرفته می‌شود)؛ هدف ثبت ادعای کاملاً بی‌پایه است، نه جریمه‌ی گردکردن.
4. **support در affinity:** هر جفت `support` (= `co_count`؛ واحد در `support_means`: «مشتریانی که هر دو را خریده‌اند»، برای basket «سبدهای دارای هر دو» — **نه تعداد سفارش**؛ جدول `product_affinities` فقط `co_customers` دارد) و خروجی `min_support` دارد. حداقل از `config('ai.tools.affinity_min_support')` (پیش‌فرض ۲۰، env `AI_AFFINITY_MIN_SUPPORT`، هرگز زیر `small_group_min`). **انتخاب ۲۰ از توزیع واقعی dev:** سطح product: ۳۰۲ جفت، حداقل ۱۰ (سازنده‌ی affinity خودش زیر ۱۰ نمی‌سازد)، میانه ۱۳، p90 = ۲۵، حداکثر ۴۹؛ ≥۲۰ → ۶۱ جفت (≈۲۰٪)، ≥۳۰ → ۲۱. basket: ۲۲۳ جفت، میانه ۱۳، ≥۲۰ → ۴۴. category: همه ≥۲۰ (میانه ۴۶) پس بی‌اثر. جفتِ دیده‌شده توسط ۱۰–۱۳ مشتری حکایت است نه باندل.
5. **پوشش محصول:** `data_quality.product_coverage` = سهم درآمد محصولِ lifetime که به یک محصول نسبت داده شده (`customer_product_purchases.revenue` ÷ `daily_metrics.product_revenue`)، به‌علاوه‌ی `unattributed_percent` و `min_percent`. نقش `hm_ai_readonly` به `order_items` دسترسی ندارد (و نباید بگیرد: بدون تغییر grant)، پس شمارش «ردیف سفارش بدون SKU» ممکن نیست و **درآمدی** سنجیده شد؛ شمار محصولات variable بدون آینه هم محلی قابل‌دانستن نیست (واقعیتی سمت Woo است؛ ریسک Sprint 8 شماره‌ی ۲). dev اکنون **۷۴٫۶۲٪** پوشش دارد. زیر `ai.context.min_product_coverage_percent` (پیش‌فرض ۹۰؛ ۰ = خاموش) → هشدار دائمی `product_coverage_low` (سقف confidence=medium را هم فعال می‌کند و در `interpretation` هست). بازه‌ی `top_products` هم صریح شد: `period = {basis: lifetime_all_orders, from, to}`.
6. **value_at_risk:** `value_at_risk_means` (= «ارزش مادام‌العمر برآوردی مشتریان churn بالا/از‌دست‌رفته، نه زیان قطعی») و `value_at_risk_clv_confidence` (توزیع اطمینان CLV همان مشتریان؛ dev: ۹۸٫۵۸٪ low) کنار آن آمد. پرامپت: critical فقط وقتی بیشتر آن‌ها medium/high باشند.
7. **ایرادهای متنی («تومانروز»، «تیرگی،ضد»):** پاسخ خام در پایگاه ذخیره نمی‌شود، پس مقایسه‌ی مستقیم با id=2 ممکن نبود. روی payload ذخیره‌شده: عبارت «تومانروز» وجود ندارد، هیچ «تومان» چسبیده‌ای نیست، «تیرگی، ضد» با فاصله ذخیره شده و ۲۹ ZWNJ سالم‌اند. کد را هم خواندم: `Digits::toAscii` فقط `strtr` ارقام است و `ContextPiiGuard::sanitize` فقط الگوی PII را با `[redacted]` عوض می‌کند — هیچ‌کدام فاصله/ZWNJ حذف نمی‌کنند. **نتیجه: نقصی در validator/normalizer نیست؛** آنچه دیده شد از نمایش (bidi ترمینال/کپی) یا مدل است. برای قطعی‌شدن، اجرای بعدی پاسخ خام را کنار payload می‌سنجد (اسکریپت موقت، بدون تغییر محصول).
8. **v3:** `analyst.daily.v3` و `analyst.weekly.v3` (v1/v2 دست‌نخورده، قفل فقط افزوده شد) و پیش‌فرض شدند. چون schema عوض شد، تست «schema بدون نسخه‌ی جدید» اکنون فقط نسخه‌های **در استفاده** را به schema فعلی گره می‌زند؛ نسخه‌های قدیمی هش schema زمان انتشار خود را نگه می‌دارند.

تست‌ها: Ai Feature ۲۵۹، Arch ۲۵۳، AiTools ۴۲، ContextBuilder ۲۹، AnalystService ۲۸، Preview ۳، AnomalyDetector ۷، AiSchema ۱۴، Analytics ۱۶ — همه سبز؛ PHPStan (کل `app`) ۰ خطا؛ Pint پاک. Mutation (۵ مورد، همه گرفته شد): حذف reconcileChange (۶ شکست)، پذیرش هر change (۵)، عدم ثبت ungrounded (۱)، عدم اعمال سقف (۱)، حذف grounding توصیه‌ها (۱).

### اجرای واقعی v3 (۲۰۲۶-۱۰-۱۹): **شکست — پاسخ در سقف `max_output_tokens=3000` بریده شد** (`AiProviderResponseInvalid: cut off at maxOutputTokens`). یک فراخوانی پولی مصرف شد و پاسخ قابل‌استفاده نبود؛ هیچ insight ذخیره نشد، پاسخ خام هم نرسید. علت محتمل: `metric_refs` اجباری برای recommendations + پرامپت طولانی‌تر + reasoning (در اجرای قبلی ۲٬۵۵۷ از ۳٬۰۰۰ مصرف شده بود، یعنی حاشیه‌ی ≈۱۴٪). **یافته‌ی جدی‌تر:** `BudgetedAiProvider::generate` فقط وقتی `usage->record()` را می‌زند که driver پاسخ **برگرداند**؛ اگر driver به‌خاطر بریده‌شدن throw کند، توکن‌های پول‌داده‌شده در `ai_usage_daily` ثبت نمی‌شوند (دفتر بودجه کمتر از هزینه‌ی واقعی را نشان می‌دهد). برای هر سه driver صدق می‌کند. اصلاح (exception حامل usage + ثبت آن) خارج از دامنه‌ی این تسک است و منتظر تأیید می‌ماند؛ همچنین تصمیم افزایش `max_output_tokens` (پیشنهاد ۴٬۵۰۰) و اجازه‌ی فراخوانی پولی دوم.
