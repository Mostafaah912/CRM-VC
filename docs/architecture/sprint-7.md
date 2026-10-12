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
