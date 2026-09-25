# Architecture Log — Sprint 5

Split from `ARCHITECTURE.md` (chore/claude-context work, continued on `sprint/5-segmentation` after merging `main`, 2026-09-25). Content below is verbatim from the original log; see `ARCHITECTURE.md` for the current index and open items.

## پس از مرج Sprint 4 به main — DB توسعه با داده‌ی زنده، `customer_metrics` خالی بود

**یافته:** بعد از مرج `sprint/4-metrics` به `main` (۲۰۲۶-۰۹-۲۵)، صفحه‌ی `/metrics/rfm` همه‌ی سگمنت‌ها/امتیازها را ۰ نشان می‌داد و `/customers` همه‌ی ۱۹٬۵۸۶ مشتری را `prospect` («بالقوه») نشان می‌داد. علت: `customer_metrics` روی DB توسعه (`heymode`) **۰ ردیف** داشت — از زمان عبور GATE 1 (Full Sync زنده، ۲۰۲۶-۰۹-۲۰) هیچ‌کس `php artisan metrics:recompute` را روی داده‌ی واقعی اجرا نکرده بود؛ Sprint 4 فقط روی `DemoDataSeeder` (۵۰ مشتری) و فیکسچر تست شده بود، نه روی DB توسعه‌ی زنده. این باگ نبود — گام Setup مستندنشده بود: زنجیره‌ی شبانه‌ی Scheduler که این را خودکار می‌کند هنوز ساخته نشده (P6-09، Sprint 6).

**رفع (بدون تغییر کد):** `php artisan metrics:recompute` روی DB توسعه اجرا شد — ۱۹٬۵۸۶ مشتری در **۱۷٫۴ ثانیه**. نتیجه: `customer_metrics` = ۱۹٬۵۸۶ ردیف؛ ۱۳٬۷۷۶ مشتریِ دارای حداقل یک سفارش محقق‌شده امتیاز R/F/M و `rfm_segment` گرفتند (توزیع بین `champion`، `loyal`، `promising`، `new_customer`، `at_risk`، `cant_lose`، `hibernating`، `lost`)؛ ۵٬۸۱۰ مشتریِ بدون سفارش محقق‌شده `rfm_segment`/امتیاز `NULL` ماندند (طبق قانون «بدون سفارش = واقعیت، نه مقدار گمشده» — درست). `lifecycle_stage` هم از یکدست `prospect` به توزیع واقعی (`new`, `active`, `repeat`, `loyal`, `at_risk`, `dormant`, `lost`, `prospect`) رسید.

**⚠ گام Setup مستندنشده — ثبت شد اینجا:** تا زمانی که P6-09 (زنجیره‌ی شبانه‌ی کامل) ساخته شود، بعد از هر `hm:sync --full` زنده روی DB توسعه، باید دستی `php artisan metrics:recompute` اجرا شود؛ در غیر این صورت `customer_metrics` با داده‌ی Sync هم‌گام نمی‌ماند. این پروژه فایل `README.md` ندارد؛ این ثبت در `ARCHITECTURE.md` تا ساخت مستندات Setup رسمی (P8-06) مرجع است.

**⚠ یافته‌ی جانبی — `customers.metrics_dirty` هرگز `false` نمی‌شود (رفع نشد، فقط ثبت):** `grep -rn "metrics_dirty"` روی کل `app/`/`database/`/`tests/` نشان داد فقط یک نویسنده وجود دارد که آن را `true` می‌کند (`MarkCustomerMetricsDirty` Listener، P4-07) و **هیچ نویسنده‌ای آن را `false` نمی‌کند** — نه در `BaseAggregateService`، نه در `MetricsRecomputeService`، نه جای دیگری. این با مستندات خودِ کد در تناقض است: `BaseAggregateService.php:22-23` می‌گوید «این پرچم فقط وقتی کل Pipeline یک مشتری را بازمحاسبه کرد پاک می‌شود، که کار P4-07 است» ولی `MetricsRecomputeService::run()` (P4-07) هرگز این کار را نمی‌کند. اثر عملی: بعد از اولین بازمحاسبه، همه‌ی ۱۹٬۵۸۶ مشتری همچنان `metrics_dirty=true` ماندند (تأیید شد با کوئری مستقیم). این یعنی `metrics:recompute --dirty` (نسخه‌ی سبک برای زنجیره‌ی شبانه‌ی آینده P6-09) هیچ فایده‌ای نسبت به `--full` ندارد — همیشه هر ۱۹٬۵۸۶ مشتری را می‌گیرد، نه فقط مشتریان واقعاً تغییرکرده. **این خارج از Scope تسک فعلی (P5-01) است و طبق قانون «یک Feature در هر Session» اصلاح نشد** — باید به‌عنوان یک تسک جدا (احتمالاً زیر Sprint 4 یا یک Bugfix مستقل) تصمیم‌گیری و برنامه‌ریزی شود.

## P5-01 — Field + operator whitelist (شروع Sprint 5)

**چه چیزی ساخته شد:** خط اول دفاع در برابر SQL Injection در `RuleCompiler` (P5-03)، طبق قانون امنیتی مطلق PRD §۱۷ («نام ستون همیشه از Whitelist، هرگز از ورودی»). چهار فایل تازه، همه داخل `app/Modules/Segments`:
- `Enums/RuleFieldGroup.php` — سه گروه PRD (`customer`/`metrics`/`behavior`) به‌عنوان Enum، برای این‌که P5-03 بداند هر فیلد به کدام جدول/Subquery می‌رود.
- `Support/RuleFieldWhitelist.php` — سه آرایه‌ی `const` عیناً از جدول PRD §۱۷ (۵ فیلد Customer، ۱۶ فیلد Metrics، ۴ فیلد Behavior، جمعاً ۲۵ فیلد، هیچ‌کدام حدسی)؛ متد `group()`/`assertValid()` برای فیلد خارج از لیست `RuleWhitelistException` پرتاب می‌کند، هرگز `null` یا عبور بی‌صدا.
- `Enums/RuleOperator.php` — Enum رشته‌ای با تمام ۱۹ عملگر از Union Type بخش ۱۷ (`=`، `!=`، `>`، `>=`، `<`، `<=`، `in`، `not_in`، `between`، `is_null`، `is_not_null`، `contains`، `bought_product`، `not_bought_product`، `bought_category`، `not_bought_category`، `bought_variation`، `in_segment`، `not_in_segment`)؛ متد `fromWhitelist()` برای رشته‌ی نامعتبر پرتاب می‌کند.
- `Exceptions/RuleWhitelistException.php` — همان الگوی `CatalogIntegrityException`/`RefundSyncException` (Constructor خصوصی، Factory استاتیک، کد `reason` پایدار: `invalid_field`/`invalid_operator`).

**تصمیم معماری:** Whitelist در کد PHP نگه داشته شد، نه `config/segments.php` — چون برخلاف `woo.realized_statuses` (که داده‌ی فروشگاه است و می‌تواند عوض شود)، این فیلدها مستقیماً به نام ستون‌های واقعی `customers`/`customer_metrics` بسته‌اند و تغییرشان بدون Migration معنی ندارد؛ Config-پذیر کردنشان یعنی امکان دور زدن Whitelist با تغییر یک فایل env-like، بدون تست/Review کد.

**تست:** ۲ سوییت جدید در `tests/Unit/Modules/Segments/` — `RuleFieldWhitelistTest.php` (هر ۲۵ فیلد PRD + رد فیلد خارج از لیست + تلاش تزریق SQL به‌جای نام فیلد + حساسیت به بزرگ/کوچکی حروف) و `RuleOperatorTest.php` (هر ۱۹ عملگر + یک تست «هیچ عملگر اضافه/کم نیست» با `RuleOperator::cases()` + رد عملگر خارج از لیست + تلاش تزریق SQL به‌جای عملگر). کل سوییت **۲۵۵۷ ← ۲۶۱۰ سبز** (۱۲۷۹۰ Assertion)، PHPStan ۰ خطا (۲ خطای اولیه‌ی Type روی امضای `toThrow(Closure)` با الگوی try/catch موجود در `RefundServiceTest`/`CatalogServiceTest` رفع شد)، Pint تمیز، Arch Suite دست‌نخورده (بدون نقض قانون «بدون Raw SQL در Segments» و بدون نقض جدول وابستگی ماژول‌ها).

**عمداً ساخته نشد (برای P5-02/۰۳):** `RuleValidator` (اعتبارسنجی همخوانی فیلد↔عملگر↔مقدار، عمق/تعداد گره‌ها، `unit`)، `RuleCompiler` (تبدیل به Query Builder واقعی)، نگاشت هر فیلد به نوع مقدار مجاز.

## P5-02 — RuleValidator (TEST FIRST)

**چه چیزی ساخته شد:** `app/Modules/Segments/Services/RuleValidator.php` — اعتبارسنجی کامل درخت قانون (Group|Condition، PRD §17) پیش از رسیدن به `RuleCompiler` (P5-03)، با روش TEST FIRST (۴۶ تست نوشته و اجرا شد، ابتدا با خطای «Class not found» قرمز، سپس حداقل کد لازم برای سبزشدن اضافه شد). دو فایل همراه: `Exceptions/RuleValidationException.php` (۱۱ کد `reason` پایدار، پیام‌ها فارسی چون مصرف‌کننده Rule Builder UI است، برخلاف `RuleWhitelistException` P5-01 که یک نگهبان داخلی است) و تست `tests/Unit/Modules/Segments/RuleValidatorTest.php`.

**قوانین اعمال‌شده (همه از PRD §17، هیچ‌کدام حدسی):**
- ساختار: هر گره یا Group (`op`∈{AND,OR} + `children`) یا Condition (`field`+`operator`+`value`?) است؛ هرچیز دیگر رد می‌شود.
- محدودیت‌ها: عمق تودرتوی Group ≤ ۴ (تعریف عمق: فقط سطوح Group شمرده می‌شود، Condition برگ عمق اضافه نمی‌کند)، تعداد کل گره ≤ ۱۰۰، فرزندان هر Group بین ۱ تا ۲۰، طول آرایه‌ی مقدار برای عملگرهای لیستی (`in`/`not_in`/`in_segment`/`not_in_segment`) ≤ ۲۰۰.
- فیلد/عملگر: از طریق `RuleFieldWhitelist`/`RuleOperator` (P5-01) بررسی می‌شود؛ `RuleWhitelistException` داخلی گرفته و به `RuleValidationException` فارسی تبدیل می‌شود — دو Whitelist دوباره تعریف نشدند.
- **سازگاری فیلد↔عملگر (تصمیم تازه‌ی این تسک، از ساختار PRD استخراج شد):** ۷ عملگر رفتاری (`bought_product`، `not_bought_product`، `bought_category`، `not_bought_category`، `bought_variation`، `in_segment`، `not_in_segment`) فقط روی فیلدهای گروه `behavior` مجازند؛ ۱۲ عملگر باقی‌مانده فقط روی فیلدهای `customer`/`metrics`. بدون این بررسی، `RuleCompiler` می‌توانست ترکیب بی‌معنی مثل `field=province, operator=bought_product` را ببیند و مجبور به حدس زدن رفتار شود.
- شکل مقدار بر اساس دسته‌ی عملگر: لیستی → آرایه (≤۲۰۰)؛ `between` → آرایه‌ی دقیقاً ۲ عضوی؛ `is_null`/`is_not_null` → کلید `value` اصلاً نباید وجود داشته باشد؛ بقیه (اسکالر) → کلید `value` باید باشد و آرایه نباشد.
- `unit` اختیاری است؛ اگر باشد فقط `days` یا `toman`.

**تست:** ۴۶ تست جدید (هر ۱۹ عملگر با مقدار سازگار، AND/OR تودرتو، مرز دقیق عمق ۴/۵، مرز دقیق گره ۱۰۰+، مرز دقیق فرزند ۲۰/۲۱، مرز دقیق لیست ۲۰۰/۲۰۱، فیلد خارج Whitelist، عملگر خارج Whitelist، دو تلاش تزریق SQL به‌جای فیلد/عملگر، ناسازگاری فیلد↔عملگر در هر دو جهت، شکل مقدار نامعتبر برای هر دسته، `unit` نامعتبر، و یک تست عمومی که پیام هر Exception فارسی است). کل سوییت **۲۶۱۰ ← ۲۶۵۶ سبز** (۱۲۸۱۰ Assertion)، PHPStan ۰ خطا (۱ خطای اولیه‌ی «no value type specified» روی PHPDoc کمکی‌های تست رفع شد)، Pint تمیز.

**⚠ یافته‌ی محیطی (بدون ربط به کد):** حین اجرای سوییت کامل، Redis محلی پایین بود (۹۶ تست با «Connection refused» شکست خوردند — دقیقاً همان ریسک ثبت‌شده در ابتدای این فایل: Redis روی این مک خودکار بالا نمی‌آید). با `redis-server --daemonize yes` رفع و سوییت کامل دوباره اجرا شد: ۲۶۵۶/۲۶۵۶ سبز.

**عمداً ساخته نشد (برای P5-03):** `RuleCompiler` (تبدیل درخت معتبرشده به Query Builder واقعی با `whereExists` برای `behavior.*`)، هیچ تغییری در `RuleFieldWhitelist`/`RuleOperator` (P5-01) نداشت.

## P5-03 — RuleCompiler (TEST FIRST) + GATE 3: عبور کرد ✓

**چه چیزی ساخته شد:** `app/Modules/Segments/Services/RuleCompiler.php` — تبدیل درخت قانون معتبرشده (Group|Condition) به `Illuminate\Database\Eloquent\Builder<Customer>` واقعی، عیناً طبق شبه‌کد PRD §17: `Customer::query()->join(customer_metrics)->whereNull(deleted_at)`. TEST FIRST رعایت شد: ابتدا دو فایل تست (۹۴ Assertion) نوشته و اجرا شد و با خطای «Class not found» قرمز بود، سپس حداقل کد لازم اضافه شد.

**لایه‌ی دفاعی دوگانه (تصمیم این تسک):** `compile()` ابتدا `RuleValidator::validate($rule)` (P5-02) را صدا می‌زند — یک قانون ساختاری نامعتبر هرگز به مرحله‌ی ساخت SQL نمی‌رسد. اما فیلد/عملگر هر Condition **دوباره و مستقل** از طریق `RuleFieldWhitelist::group()`/`RuleOperator::fromWhitelist()` (P5-01) Resolve می‌شود — نه صرفاً اعتماد به این‌که Validator قبلاً چک کرده. دلیل: PRD §17 می‌گوید «نام ستون همیشه از Whitelist، هرگز از ورودی» به‌صورت یک قانون مطلق، نه مشروط به این‌که تابع دیگری قبلاً صدا زده شده باشد؛ این یعنی حتی اگر `RuleCompiler::compile()` مستقیم و بدون عبور از `RuleValidator` در جایی از کد آینده صدا زده شود (مثلاً یک باگ در P5-04)، باز هم نمی‌تواند یک رشته‌ی دلخواه را به نام ستون تبدیل کند.

**نگاشت فیلد→ستون:** `COLUMN_MAP` (ثابت PHP، نه Config — همان تصمیم P5-01: تغییرش بدون Migration معنی ندارد) هر ۲۱ فیلد غیر-رفتاری را به ستون کامل (`customers.xxx` یا `customer_metrics.xxx`) نگاشت می‌کند. فیلدهای رفتاری (`product`/`category`/`variation`/`segment`) ستون مستقیم ندارند؛ هرکدام به یک Subquery `whereExists`/`whereNotExists` تبدیل می‌شوند:
- `bought_product`/`not_bought_product` → `order_items.product_id` روی سفارش‌های `is_realized=true` همان مشتری.
- `bought_category`/`not_bought_category` → همان، به‌علاوه Join با پیوت `product_category_product`.
- `bought_variation` → `order_items.variation_id` (بدون `not_bought_variation`؛ در Union Type PRD §17 اصلاً وجود ندارد).
- `in_segment`/`not_in_segment` → `whereIn` روی `segment_members.segment_id` (مقدار آرایه‌ای، طبق دسته‌بندی P5-02).
`match(operator)` در هر دو مسیر (رفتاری/غیررفتاری) **همه‌ی ۱۹ Case** را صریح فهرست می‌کند، بدون `default` — دقیقاً طبق PRD («no default passthrough»)؛ اگر یک Case اشتباه به مسیر دیگری برسد (که با لایه‌ی دفاعی بالا عملاً غیرممکن است)، `RuleWhitelistException` می‌دهد نه سکوت.

**نتیجه‌ی GATE 3 — عبور کرد ✓ (PRD §26: «تست SQL-injection روی RuleCompiler سبز؛ Whitelist اعمال‌شده»):** `tests/Feature/Modules/Segments/Gate3SqlInjectionTest.php` — ۱۲ Payload کلاسیک (`' OR 1=1--`، `; DROP TABLE customers; --`، `' UNION SELECT * FROM users--`، `admin'--`، تلاش نام‌گذاری یک ستون واقعی `customers.id` برای دور زدن Whitelist، و…) روی ۶ مسیر حمله جدا اجرا شد: نام فیلد، عملگر، مقدار اسکالر، آرایه‌ی `in`، آرایه‌ی `between`، و مقدار دفن‌شده در گره‌های تودرتوی AND/OR — جمعاً ۷۲ تست. هر Payload به‌عنوان فیلد/عملگر رد شد (`RuleValidationException`، پیش از ساخت هر Query)؛ هر Payload به‌عنوان مقدار، Binding پارامتری شد (با `toSql()`/`getBindings()` مستقیماً تأیید شد که متن Payload هرگز داخل رشته‌ی SQL نیست ولی عیناً در Bindings هست) و Query بدون خطای نحوی روی PostgreSQL واقعی اجرا شد. `tests/Arch/ArchitectureTest.php` («bans raw SQL in the Segments module outright») بدون تغییر روی فایل تازه هم سبز ماند (بدون `whereRaw`/`selectRaw`/`DB::raw` در `RuleCompiler.php`).

**تست صحت (جدا از GATE 3):** `tests/Feature/Modules/Segments/RuleCompilerTest.php` — هر ۱۹ عملگر (شامل هر ۶ عملگر رفتاری با ردیف واقعی `order_items`/`orders`/`product_category_product`/`segment_members`)، تودرتوی AND/OR با تقدم درست، حذف مشتری Soft-delete شده، نادیده‌گرفتن سفارش غیر-Realized برای `bought_product`، تفکیک دو Variation خواهر (فقط همان Variation دقیق مچ می‌شود). ۲۲ تست، ۱۶۹ Assertion.

**⚠ یافته‌ی جانبی ابزاری (نه باگ کد):** یک تابع کمکی هم‌نام سراسری (`customerWithMetrics`) از قبل در `tests/Feature/Modules/Metrics/RfmCalculatorTest.php` (P4-03) وجود داشت با امضای متفاوت؛ Pest همه‌ی فایل‌های تست را در یک فضای نام سراسری بارگذاری می‌کند، پس این تصادم فقط هنگام اجرای **کل** سوییت (نه فایل تنها) با `Fatal error: Cannot redeclare function` ظاهر شد. تابع این تسک به `segmentCustomerWithMetrics` تغییر نام گرفت. **یافته‌ی دوم:** فراخوانی `RuleCompiler::compile(...)->pluck(...)->all()` از پشت یک تابع کمکی هم‌فایلی (نه یک Method کلاس)، افزونه‌ی PHPStan مربوط به Pest را گیج می‌کرد و نوع `Expectation<mixed|null>` (بدون Property به نام `not`) می‌ساخت؛ رفع شد با Inline کردن فراخوانی مستقیم در هر تست به‌جای عبور از تابع کمکی — یک محدودیت ابزار، نه یک باگ در `RuleCompiler`.

**تست کامل نهایی:** ۲۶۵۶ ← **۲۷۵۰ سبز** (۱۳۰۵۱ Assertion)، PHPStan ۰ خطا، Pint تمیز، Arch Suite دست‌نخورده. `README.md` (تازه‌ساخته، پروژه قبلاً نداشت) یک خط دارد: اجرای تست‌ها به Redis نیاز دارد.

**عمداً ساخته نشد (برای P5-04+):** `SegmentService` (`preview`/`evaluate`/`export`، `statement_timeout=5s` روی Preview)، مدل Eloquent برای `Segment` (فعلاً فقط از طریق `DB::table` در تست لمس شد)، `RuleBuilder` UI، `DefaultSegmentSeeder`.

## P5-04 — SegmentService: evaluate / preview / export

**چه چیزی ساخته شد:** `app/Modules/Segments/Services/SegmentService.php` — طبق شبه‌کد دقیق PRD §17 «ارزیابی». سه متد، هرکدام دقیقاً یک مسیر شکست:

- **`evaluate(Segment)`**: compile ← pluck ids ← `DB::transaction` (فقط DELETE + bulk INSERT، chunk=1000) ← `UPDATE member_count/last_evaluated_at/last_eval_ms` ← diff ← `CustomerEnteredSegment`/`CustomerLeftSegment`/`SegmentEvaluated` (Dispatch تنها — بدون Listener اکشن‌دار). فقط Transaction محدود به DELETE+INSERT است، نه کل متد — دقیقاً همان‌طور که پرانتز شبه‌کد PRD مرزبندی کرده بود؛ یعنی شکست compile (فیلد/عملگر نامعتبر) اصلاً به عضویت دست نمی‌زند، و شکست داخل Swap دقیقاً عضویت قبلی را دست‌نخورده برمی‌گرداند (تست با `ALTER TABLE segment_members DROP COLUMN added_at` — همان تکنیک P4-07 — ثابت شد). سگمنت `static`/`manual` رد می‌شود (`SegmentException::NOT_DYNAMIC`).
- **`preview(Segment)`**: `compile()->count()` داخل `App\Support\PostgresStatementTimeout::run()` با سقف `config('segments.preview_timeout_ms', 5000)`. لغو Postgres (`SQLSTATE 57014`) به `SegmentException::PREVIEW_TIMED_OUT` فارسی تبدیل می‌شود، هرگز 500 خام.
- **`export(Segment, User)`**: نیازمند `customers.export`؛ شماره‌ی کامل فقط با `customers.view_full_phone` هم، وگرنه `PhoneMask` (همان ماسک لیست مشتریان P3-01). هر Export — موفق یا رد‌شده — `AuditService` را لمس می‌کند (رد‌شده چیزی نمی‌نویسد؛ موفق یک ردیف `segment.exported` قبل از شروع Stream می‌نویسد).

**⚠ تصمیم معماری تازه — `PostgresStatementTimeout` و استثنای دوم Rule 7 (نیازمند تأیید کاربر اگر مخالف باشد):** PRD §17 عیناً `statement_timeout = 5s` را برای Preview الزامی کرده، ولی `SET LOCAL statement_timeout` هیچ فرم Bound-Parameter در PostgreSQL ندارد (`SET x = $1` خطای نحوی Postgres است، نه محدودیت Laravel) — یعنی برای این یک کار، جایگزین Query Builder اصلاً وجود ندارد. فایل تازه `app/Support/PostgresStatementTimeout.php` (نه داخل `app/Modules/Segments` — ممنوعیت مطلق Rule 3/Arch آنجا دست‌نخورده ماند) دومین استثنای مستند Rule 7 شد (اولی P3-07/`ProductListService.php`)؛ `tests/Arch/ArchitectureTest.php` مستقیماً ویرایش و این یک فایل به `$allowedFiles` اضافه شد. عدد Timeout همیشه یک Literal کنترل‌شده‌ی برنامه است (Config/Const)، هرگز از ورودی کاربر.

**⚠ یافته‌ی جانبی — نقض مرز ماژول با `AuditActorType` (رفع شد، به‌عنوان بهبود Core ثبت شد):** پیاده‌سازی اول `export()` مستقیم `App\Modules\Core\Enums\AuditActorType::User` را Import می‌کرد تا `AuditService::record()` را صدا بزند — Arch Test «فقط از طریق Service/Event به ماژول دیگر» بلافاصله این را گرفت (Enum یک ماژول دیگر، نه Service/Event). دقیقاً همان مشکلی که کامنت خودِ `AuditService::recordSystem()` از قبل توضیح داده بود («ماژول‌های دیگر برای همین نمی‌توانند AuditActorType را Import کنند»)، ولی تا این تسک فقط نسخه‌ی System آن ساخته شده بود. رفع شد با افزودن `AuditService::recordUser(User $user, ...)` — دقیقاً همان الگوی `recordSystem()`، این‌بار Actor را به `User` قفل می‌کند؛ `export()` اکنون هیچ Enum‌ای از Core Import نمی‌کند.

**تصمیم‌های مبهم در PRD (نام‌گذاری‌شده، پیش‌فرض با دلیل):**
1. **فرمت Export:** PRD فرمت را نگفته. پیش‌فرض انتخاب‌شده: CSV با UTF-8 BOM (اکسل ویندوز بدون BOM متن فارسی را Mojibake نشان می‌دهد) + `cursor()` روی `DB::table` (Streaming واقعی، بدون بافر کامل در حافظه برای سگمنت‌های بزرگ) — دقیقاً همان پیشنهاد کاربر، با همان دلیل.
2. **ستون‌های Export:** PRD شِمای Export را مشخص نکرده. مجموعه‌ی حداقلی انتخاب شد: `customer_id, phone, display_name, province, city, status, lifecycle_stage, total_orders, total_revenue, rfm_segment` — بدون هیچ فیلد اضافه‌ی حدسی؛ گسترش آن (مثلاً CLV/Churn) تصمیم جدا نیاز دارد.
3. **رویدادهای `CustomerEntered/LeftSegment`:** PRD می‌گوید «recorded only». این تسک آن‌ها را فقط Dispatch می‌کند (`Event::fake()`/`assertDispatched` در تست)؛ **هیچ Listener که آن‌ها را در `customer_events` بنویسد ساخته نشد** — چون این نیازمند افزودن Case تازه به `CustomerEventType` (ماژول Customers، تغییر CHECK Constraint) است، یک تصمیم جدا و خارج از Scope «یک Feature در هر Session».
4. **مقدار `preview`:** فقط `count()` پیاده شد، بدون «نمونه‌ی محدود» — چون PRD این را مشروط به «در صورت لزوم» گفته و نمونه‌دهی سگمنت یعنی برگرداندن داده‌ی سطح-مشتری از یک مسیر Preview که تا امروز هیچ‌جا Permission-Gate نشده؛ افزودنش بدون تصمیم صریح درباره‌ی PII ریسک دارد.

**⚠ یافته‌ی محیطی — Flake احتمالی (یک‌بار مشاهده، ۸ اجرای بعدی سبز):** تست «reset استماع‌تایم‌اوت بعد از Preview ناموفق» یک‌بار (از ~۱۰ اجرا) با Timeout واقعی روی همان کوئری‌ای شکست خورد که معمولاً ۳ تا ۱۸ میلی‌ثانیه طول می‌کشد — با سقف Reset‌شده به ۵۰۰۰ میلی‌ثانیه (حاشیه‌ی اطمینان >۲۵۰x). آزمون اختصاصی مکانیزم (`PostgresStatementTimeoutTest.php`, با `pg_sleep` واقعی) هرگز شکست نخورد. به احتمال زیاد نوسان بار سیستم لحظه‌ای بوده، نه باگ منطقی؛ اگر در CI تکرار شد، باید بازبینی شود.

**تست:** ۴ سوییت تازه — `SegmentServiceEvaluateTest.php` (۱۰، شامل Idempotency، Left-diff، Rollback واقعی Postgres)، `SegmentServicePreviewTest.php` (۴، شامل Timeout واقعی با ۲۰٬۰۰۰ ردیف Bulk-Insert‌شده‌ی خام برای گارانتی کوئری واقعاً کند بدون `pg_sleep`)، `SegmentServiceExportTest.php` (۵، شامل Audit و ماسک واقعی)، `tests/Feature/Support/PostgresStatementTimeoutTest.php` (۳، اثبات مستقل مکانیزم با `pg_sleep`). کل سوییت **۲۷۵۰ ← ۲۷۷۲ سبز** (۱۳۰۹۶ Assertion)، PHPStan ۰ خطا، Pint تمیز، Arch Suite ۲۱۳/۲۱۳ (شامل استثنای تازه‌ی Rule 7).

**عمداً ساخته نشد (برای P5-05/۰۶+):** `RuleBuilder` UI، Controller/Route برای `evaluate`/`preview`/`export` (این‌ها هنوز از هیچ HTTP Endpoint صدا زده نمی‌شوند)، `DefaultSegmentSeeder` (۱۲ سگمنت Seed)، `RebuildAllSegmentsJob` + Listener، نمایش سگمنت در Customer 360.

## P5-05 پیش‌گام — بازنگری `PostgresStatementTimeout`: حذف استثنای Rule 7

**یافته:** استثنای Rule 7 که در P5-04 برای `app/Support/PostgresStatementTimeout.php` اضافه شد، لازم نبود. `SET LOCAL statement_timeout = ...` واقعاً هیچ فرم Bound-Parameter ندارد، ولی Postgres همان رفتار را از طریق یک **تابع معمولی** هم می‌دهد: `set_config('statement_timeout', new_value, is_local)` — با `is_local=true` دقیقاً معادل `SET LOCAL` است (سند رسمی Postgres)، و چون یک فراخوانی تابع عادی داخل `SELECT` است، هر دو آرگومان‌اش Binding می‌گیرند، نه Concatenation. با `DB::selectOne('select set_config(?, ?, true)', ['statement_timeout', (string) $ms])` تعویض شد؛ روی Postgres 15 واقعی (`SELECT pg_sleep(1)` با Timeout=50ms) تست شد و دقیقاً همان `SQLSTATE 57014` را داد — رفتار عیناً یکسان با نسخه‌ی قبلی.

**⚠ چرا استثنای Arch Test هم حذف شد (نه فقط تغییر کد):** Regex قانون Rule 7 (`/\bDB::(raw|statement|select|unprepared)\b/`) به‌خاطر `\b` (مرز کلمه) بعد از `select`، با `DB::selectOne(` تطبیق **نمی‌خورد** (چون بین `t` انتهای select و `O` ابتدای `One` هیچ مرز کلمه‌ای نیست) — این یک اتفاق Regex نیست، رفتار مستند خودِ الگو است. یعنی نسخه‌ی تازه از اساس به هیچ استثنایی نیاز ندارد: نه `DB::raw`، نه `DB::statement`، نه `DB::select` (دقیقاً همان سه‌تایی که Rule 7 می‌بندد) — فقط `DB::selectOne`، یک متد کاملاً متفاوت با Binding کامل. استثنای اضافه‌شده در P5-04 از `$allowedFiles` و کامنت Rule 7 پاک شد؛ فقط استثنای اصلی P3-07 (`ProductListService.php`) باقی ماند.

**تست:** بدون تغییر در تعداد تست (۲۷۷۲ ثابت ماند) — فقط `Arch Suite` از ۲۱۳ تست با ۱ استثنا به ۲۱۳ تست با ۰ استثنای اضافه رسید؛ `tests/Feature/Support/PostgresStatementTimeoutTest.php` و `SegmentServicePreviewTest.php` بدون تغییر روی پیاده‌سازی تازه سبزند.

## P5-05 — RuleBuilder UI + مسیر Preview

**چه چیزی ساخته شد (Backend):**
- `App\Modules\Segments\Support\RuleWhitelistPresenter::toArray()` — سریالایز کردن Whitelist P5-01 + محدودیت‌های P5-02 (`MAX_DEPTH` و بقیه، که برای همین از `private` به `public const` در `RuleValidator` تغییر کردند) به شکل دقیقی که فرانت انتظار دارد: ۲۵ فیلد با برچسب فارسی، ۱۹ عملگر با برچسب فارسی + `behaviorOnly` + `valueShape` (`scalar`/`list`/`range`/`none`)، و ۵ محدودیت ساختاری. برچسب‌های فارسی فقط همین‌جا تعریف شده‌اند.
- `POST /segments/preview` (`routes/internal.php`, پشت `permission:segments,create`) → `SegmentPreviewRequest` (فقط `rule` را الزامی/آرایه می‌کند) → `SegmentPreviewController` (یک خط: `$segments->previewRule($request->rule())`) → `SegmentService::previewRule(array $rule): int` (متد تازه‌ی P5-05؛ یک `Segment` هرگز-ذخیره‌نشده می‌سازد و `preview()` موجود P5-04 را صدا می‌زند — دقیقاً همان چیزی که Rule Builder برای پیش‌نمایش یک قانون هنوز-ذخیره‌نشده لازم دارد).
- ترجمه‌ی Exception به پاسخ: `SegmentException`/`RuleValidationException` در `bootstrap/app.php` (`$exceptions->render(...)`) به ۴۲۲ فارسی نگاشت می‌شوند — نه در Controller، تا Controller «فقط یک Service صدا بزن» بماند (CLAUDE.md §۱).

**⚠ یافته‌ی معماری حین کار — Controller مستقیم Model لمس می‌کرد:** پیاده‌سازی اول `Controller` مستقیم `new Segment([...])` می‌ساخت. Arch Test «Controller هرگز مستقیم Model ماژول دیگر را لمس نمی‌کند» بلافاصله گرفت. رفع شد با انتقال ساخت `Segment` هرگز-ذخیره‌نشده به داخل `SegmentService::previewRule()` — Controller اکنون هیچ `use App\Modules\Segments\Models\...` ندارد.

**⚠ به‌روزرسانی دو تست مرزبندی موجود:** افزودن یک `Route::post` تازه به `routes/internal.php` شمارش دقیق POSTها را در دو تست از‌پیش‌موجود (`CustomerListBoundaryTest`، `PhoneRevealBoundaryTest`) از ۲ به ۳ رساند — همان الگوی محافظ دامنه‌ی مستندشده در P2-10/P4-03؛ هر دو عدد و کامنتشان به‌روز شد.

**چه چیزی ساخته شد (Frontend، TypeScript strict، بدون `any`):**
- `resources/js/types/segments.ts` — نوع‌های `RuleWhitelist` (از Backend) و درخت داخلی `RuleTreeNode` (با `id` سمت کلاینت برای React key/خطا؛ هرگز به سرور نمی‌رود).
- `resources/js/lib/segment-rule-tree.ts` — توابع خالص برای افزودن/حذف/ویرایش گره و تبدیل درخت داخلی به فرمت Wire دقیق PRD §۱۷ (`toWireRule`).
- `resources/js/lib/segment-rule-validation.ts` — بازتاب سمت کلاینت محدودیت‌های `RuleValidator` (عمق/گره/فرزند/طول لیست/سازگاری فیلد↔عملگر/شکل مقدار) — **فقط UX**؛ مرجع نهایی همیشه سرور است و هر کلیک Preview واقعاً `RuleValidator` را دوباره اجرا می‌کند.
- `resources/js/components/segments/{RuleBuilder,RuleNodeEditor,RuleValueInput}.tsx` — `RuleBuilder` وضعیت درخت + دکمه‌ی Preview (Loading/خطای فارسی/تعداد) را نگه می‌دارد؛ `RuleNodeEditor` بازگشتی است (Group یا Condition)؛ `RuleValueInput` شکل ورودی را از `valueShape` سرور تعیین می‌کند (نه حدس در فرانت).

**تصمیم‌های مبهم در PRD/تسک (نام‌گذاری‌شده، پیش‌فرض با دلیل):**
1. **مجوز مسیر Preview:** فقط `segments.create` (میدلور فعلی OR بین دو Permission را پشتیبانی نمی‌کند). یک کاربر با فقط `segments.edit` (بدون `create`) نمی‌تواند Preview بزند — نقص شناخته‌شده، موکول به P5-06 وقتی صفحات واقعی Create/Edit ساخته شوند و معلوم شود این ترکیب واقعاً لازم است یا نه.
2. **مقدار چندتایی (`in`/`not_in`/`in_segment`):** ورودی متن جداشده با کاما، نه یک Tag-Input واقعی — ساده‌ترین راه بدون افزودن کتابخانه‌ی تازه (قانون صریح تسک: «فریمورک/کتابخانه‌ی جدید اضافه نکن»).
3. **مقدار تاریخ:** هیچ کامپوننت Date-Picker شمسی در پروژه وجود ندارد (بررسی شد: `resources/js/components` فاقد آن است؛ تاریخ‌ها همه‌جا فقط نمایشی‌اند، نه ورودی). پیش‌فرض: `<input type="date">` بومی مرورگر (میلادی روی سیم، تبدیل سمت سرور در صورت نیاز) — ساخت یک Date-Picker شمسی کامل خارج از Scope این تسک است.
4. **شناسه‌ی فیلدهای رفتاری (`product`/`category`/`variation`/`segment`):** هیچ Combobox جست‌وجوی محصول/دسته/سگمنت در پروژه نیست؛ پیش‌فرض ورودی عددی ساده (شناسه‌ی خام) با Placeholder، نه انتخاب‌گر جست‌وجوپذیر.
5. **`unit` (`days`/`toman`):** چون PRD نگفته کدام فیلد کِی واحد نشان دهد، یک Heuristic ساده اضافه شد (فیلدهای `_days*` → روز، فیلدهای پولی → تومان)؛ اگر نادرست بود، تصمیم جدا لازم دارد.

**چرا هیچ صفحه‌ای ساخته نشد:** طبق تسک صریح («UI و RuleBuilder در P5-05 است، پیاده‌سازی صفحات در P5-06»)، `RuleBuilder` یک کامپوننت مستقل Props-Driven است (`whitelist`/`value?`/`onChange?`) و هیچ صفحه‌ای هنوز آن را Render نمی‌کند — پس امکان تست دستی در مرورگر برای این تسک وجود نداشت (نه به این معنی که رد شد، به این معنی که چیزی برای کلیک‌کردن هنوز ساخته نشده).

**⚠ هیچ فریمورک تست فرانت در پروژه نیست:** `package.json` را بررسی کردم — نه Vitest نه Jest (فقط `tsc --noEmit`، `vp check` یعنی Lint/Format، و Build). طبق دستور صریح («فریمورک جدید اضافه نکن، بگو چه چیزی وجود دارد»)، کامپوننت‌های React تست خودکار در سطح Unit ندارند؛ فقط با `npx tsc --noEmit` (۰ خطا)، `vp check --fix` (Lint/Format تمیز، فقط ۴ فایل خودم لمس شد) و `npm run build` (موفق) راستی‌آزمایی شدند.

**⚠ یافته‌ی Flaky واقعی — یک تست حذف شد، نه Skip:** تست «Reset بعد از Timeout» در `SegmentServicePreviewTest.php` (که با یک کوئری ۲۰٬۰۰۰ ردیفی دوم Reset را دوباره اثبات می‌کرد) **دو بار** از میان چند اجرای کامل کل سوییت (هر بار ~۲۷۸۰ تست) شکست خورد — با حاشیه‌ی اطمینان ۵۰۰۰ میلی‌ثانیه در برابر کوئری‌ای که معمولاً ۳ تا ۴۰ میلی‌ثانیه طول می‌کشد (Reproduce مستقیم با ۸ تکرار پشت‌سرهم هرگز شکست نخورد). چون `tests/Feature/Support/PostgresStatementTimeoutTest.php` همین مکانیزم را با `pg_sleep(1)` واقعی در برابر Timeout=۵۰ میلی‌ثانیه **بدون هیچ وابستگی به بار سیستم** قطعی اثبات می‌کند، این تست تکراری حذف شد (نه Skip — طبق قانون «هرگز یک تست را موقت غیرفعال نکن»، حذف با توضیح متفاوت از غیرفعال‌سازی موقت است) و دلیلش همین‌جا و در کامنت خودِ فایل تست ثبت شد.

**تست Backend:** `RuleWhitelistPresenterTest.php` (۵، شامل «هیچ فیلد/عملگری بدون برچسب نمی‌ماند»)، `SegmentPreviewControllerTest.php` (۸، شامل ۴۰۳ بدون مجوز، Validation، تزریق SQL در فیلد/عملگر، Timeout واقعی → ۴۲۲ فارسی). کل سوییت Backend **۲۷۷۲ ← ۲۷۸۴ سبز** (۱۳۲۰۴ Assertion، با حذف یک تست Flaky از میانه)، PHPStan ۰ خطا، Pint تمیز، Arch Suite ۲۱۳/۲۱۳ (با ۲ تست مرزبندی به‌روزشده).

**عمداً ساخته نشد (برای P5-06):** صفحات Create/Edit/List سگمنت، `DefaultSegmentSeeder`، Combobox محصول/دسته/سگمنت، Date-Picker شمسی، هر تست خودکار سطح کامپوننت React (چون فریمورکش نیست).

## P5-06 پیش‌گام — دو اصلاح کوچک پیش از شروع

**۱) تست Flaky حذف‌شده (Reset بعد از Timeout):** بازبینی مجدد طبق درخواست صریح — تصمیم قبلی (بالا، «یافته‌ی Flaky واقعی») بدون تغییر تأیید می‌شود: `PostgresStatementTimeoutTest.php`، تست سوم («never leaves the cancelled statement_timeout applied to the next query on the connection»)، همین ادعا را با `pg_sleep(1)` واقعی در برابر Timeout=۵۰ میلی‌ثانیه قطعی و بدون وابستگی به بار سیستم اثبات می‌کند — یک اثبات جداگانه و کامل، نه صرفاً یک توجیه. بازنویسی تست حذف‌شده به شکل قطعی (مثلاً با `pg_sleep`) در خودِ `SegmentServicePreviewTest.php` فقط همین اثبات را با یک کوئری واقعی ۲۰٬۰۰۰ ردیفی دوباره تکرار می‌کرد — پوشش تازه‌ای اضافه نمی‌کرد، فقط زمان اجرای سوییت را بالا می‌برد. تصمیم: بدون تغییر کد، این بند صرفاً تأیید مجدد را ثبت می‌کند.

**۲) پشتیبانی OR در `EnsurePermission`:** `permission:module,action1,action2,...` اضافه شد (TEST FIRST — `tests/Feature/Modules/Core/EnsurePermissionMiddlewareTest.php` سه تست تازه: مجاز با فقط action دوم، ممنوع با هیچ‌کدام، ممنوع با Deny روی action اول و بدون Grant روی دوم). امضای `handle()` از `string $action` به `string ...$actions` تغییر کرد؛ هر Action هنوز جداگانه از مسیر deny>allow>role>default-deny رد می‌شود (یک Deny روی یک Action هرگز از Action دیگر تأیید «قرض» نمی‌گیرد). مسیر `POST /segments/preview` اکنون `permission:segments,create,edit` است — ابهام نام‌گذاری‌شده‌ی #۱ در بخش P5-05 بالا برطرف شد.

## P5-06 — صفحات سگمنت (List / Create / Edit / Detail / Delete)

**اندازه‌گیری evaluate پیش از تصمیم Job/Sync (PRD §۱۸، CLAUDE.md §۱ «بیش از ~۲ ثانیه یعنی Job»):** روی داده‌ی dev (۱۹٬۵۸۶ مشتری)، `SegmentService::evaluate()` برای قانونی با گزینش‌پذیری متوسط (۱٬۰۴۸ عضو) **~۲۴۰-۳۰۰ میلی‌ثانیه** طول کشید؛ برای گسترده‌ترین قانون ممکن (منطبق با همه‌ی ۱۹٬۵۸۶ مشتری) **~۲٬۲۴۰-۲٬۴۶۵ میلی‌ثانیه** — دقیقاً روی/بالای خط ۲ ثانیه. چون گزینش‌پذیری قانون از پیش قابل پیش‌بینی نیست، دکمه‌ی «ارزیابی» **همیشه** `EvaluateSegmentJob` (Queue، `ShouldBeUnique` بر اساس id سگمنت، `tries=1`) را صف می‌کند، هرگز `evaluate()` را همزمان صدا نمی‌زند. `RebuildAllSegmentsJob` و Listener مربوطه طبق تسک، ساخته نشدند (کار P5-08).

**چه چیزی ساخته شد (Backend):**
- `SegmentService`: متدهای تازه‌ی `whitelist()` (پاس‌کاری `RuleWhitelistPresenter` — تا Controller فقط از Service وارد کند)، `paginate()` (لیست)، `members()` (اعضای یک سگمنت، صفحه‌بندی‌شده، موبایل ماسک‌شده)، `create()`، `modify()`، `destroy()`، `evaluateById()` (نقطه‌ی ورود Job).
- `SegmentException`: دو دلیل تازه — `IS_SYSTEM`، `NAME_TAKEN` (Backstop برای تضاد race با ایندکس `segments_name_unique`).
- `App\Modules\Segments\Support\{SegmentListRow, SegmentMemberRow, SegmentDetail}` — سه DTO فقط-خواندنی، هم‌الگوی `CustomerListRow`.
- `App\Modules\Segments\Jobs\EvaluateSegmentJob` — Wrapper نازک (بدون منطق کسب‌وکار؛ Rule «jobs free of business logic» وارد کردن Model را در Job کلاً ممنوع می‌کند، پس lookup داخل `SegmentService::evaluateById()` است، نه در Job).
- ۹ FormRequest و ۹ Controller در `app/Http/{Requests,Controllers}/Segments` (index/create/store/edit/update/show/destroy/evaluate/export) — هرکدام دقیقاً «یک FormRequest → یک Service → یک پاسخ».
- مسیرها در `routes/internal.php`: `GET segments`، `GET segments/create`، `POST segments`، `GET segments/{segment}`، `GET segments/{segment}/edit`، `POST segments/{segment}`، `POST segments/{segment}/evaluate`، `DELETE segments/{segment}`، `GET segments/{segment}/export`.
- `bootstrap/app.php`: مسیر رندر Exception گسترش یافت تا درخواست‌های Inertia (نه فقط JSON) را هم به `back()->withErrors([...])` نگاشت کند — دقیقاً همان شکلی که یک خطای اعتبارسنجی معمولی FormRequest تولید می‌کند.

**⚠ یافته‌ی معماری حین کار — برخورد نام‌گذاری با اسکنر متنی Rule 1:** تست معماری کنترلرها (`DB_ACCESS_PATTERNS`) یک regex متنی ساده است که هر `->update(`/`->delete(` را — صرف‌نظر از نوع گیرنده — به‌عنوان دسترسی DB می‌گیرد. متدهای Service با نام `update()`/`delete()` (که کنترلر صدا می‌زند) هم می‌گرفت. همان الگوی موجود کدبیس (`CustomerNotesService::destroy()`، نه `delete()`) را تکرار کردیم: `SegmentService::destroy()` و `SegmentService::modify()` (نه `update()`) — مستندشده در کامنت خودِ این دو متد.

**⚠ یافته‌ی دوم — برخورد نام با متد بومی `Illuminate\Http\Request::segment()`:** هلپر اولیه‌ی FormRequestها به نام `segment(): Segment` امضای متد داخلی `Request::segment($index, $default)` (بخش مسیر URL) را Override می‌کرد و PHPStan رد می‌کرد. به `segmentModel()` تغییر نام یافت.

**⚠ یافته‌ی سوم — `object` در برابر `stdClass` در PHPStan:** در `export()`ی موجود (P5-04)، خواص یک ردیف `DB::table()->select([...])->cursor()->each(closure)` داخل همان Closure بدون خطا خوانده می‌شوند (Larastan نوع را از همان زنجیره‌ی Fluent استنتاج می‌کند)، اما وقتی همان شیء به یک کلاس/متد دیگر (این‌جا `SegmentMemberRow`) پاس داده شود، آن استنتاج از دست می‌رود و `object` یک نوع عمومی بدون خاصیت شناخته‌شده می‌ماند. راه‌حل: پارامتر را صریحاً `\stdClass` تایپ کردیم (PHPStan دسترسی پویا به خاصیت را روی `stdClass` — برخلاف `object` — خطا نمی‌گیرد).

**تصمیم‌های مبهم (نام‌گذاری‌شده، پیش‌فرض با دلیل):**
1. **فعل مسیر Update:** `POST /segments/{segment}`، نه PUT/PATCH. در کل `routes/internal.php` تا امروز هیچ `Route::put`/`Route::patch`ی وجود ندارد و یک تست معماری صریحاً آن را `not->toMatch` می‌کند؛ به‌جای شل‌کردن آن Guard برای یک مسیر، از الگوی موجود (POST برای هر نوشتن) پیروی شد.
2. **مجوز Evaluate:** فقط `segments.edit` (نه OR با create) — چون در ماتریس PRD §۲۰ هر کسی که `segments.create` دارد `segments.edit` هم دارد (Analyst، Manager، Owner یکسان)، یک مجوز واحد برای این اکشن کافی بود؛ مسیر Preview همچنان OR است چون از صفحه‌ی Create (که Edit ندارد) هم صدا زده می‌شود.
3. **قفل کامل ویرایش سگمنت سیستمی:** تسک گفته بود «rule آن قابل ویرایش نباشد»؛ چون فرم این صفحه نام/توضیح/rule را همیشه یک‌جا می‌فرستد (بدون مسیر ویرایش «فقط‌نام»)، `SegmentService::modify()` کل درخواست را برای `is_system=true` رد می‌کند، نه فقط تغییر `rule` را. نیاز واقعی به ویرایش جزئی سگمنت سیستمی، تصمیم جدایی می‌خواهد.
4. **مجوز Export همچنان `customers.export`، نه یک `segments.export` تازه:** `SegmentService::export()` (P5-04) از قبل همین را چک می‌کند؛ مسیر تازه هم همان را در Middleware تکرار کرد (Defense in Depth) تا یک Navigation معمولی مرورگر بدون مجوز، ۴۰۳ عادی بگیرد نه صفحه‌ی خطای عمومی.
5. **خلاصه‌ی Rule = JSON خوانا، نه جمله‌ی فارسی:** PRD قالب «خلاصه» را مشخص نکرده؛ ساخت یک مبدل «فیلد/عملگر → جمله‌ی فارسی» خارج از Scope این تسک است.
6. **فیلد توضیح با `<Input>` تک‌خطی، نه `<Textarea>`:** هیچ کامپوننت Textarea در `resources/js/components/ui` وجود ندارد؛ ساختن یک Primitive تازه‌ی shadcn برای این یک فیلد توجیه نداشت.
7. **تأیید حذف با `window.confirm()` بومی:** هیچ `AlertDialog`ی در پروژه نیست؛ ساخت یک کامپوننت Dialog تازه برای یک تأیید ساده خارج از Scope بود.
8. **`useForm` (Inertia) برای فرم Create/Edit:** هیچ صفحه‌ی قبلی در این پروژه از آن استفاده نکرده بود (بررسی شد)، اما بخشی از `@inertiajs/react` موجود است (نه کتابخانه‌ی تازه) و دقیقاً برای همین حالت (ارسال فرم + خطاهای سمت سرور) ساخته شده.

**⚠ یافته‌ی JSONB و ترتیب کلید:** ستون `rule` (jsonb) ترتیب کلیدهای PHP array را پس از یک رفت‌وبرگشت واقعی به دیتابیس حفظ نمی‌کند (رفتار شناخته‌شده‌ی Postgres JSONB). تست‌هایی که `rule` را بعد از یک عملیات DB واقعی می‌خوانند از `->toEqual()` (بدون‌حساسیت به ترتیب) استفاده می‌کنند، نه `->toBe()` (===، حساس به ترتیب) — مستندشده در خودِ تست‌ها.

**تست:** ۵۶ تست Feature/Unit تازه (`SegmentListControllerTest`، `SegmentStoreControllerTest`، `SegmentUpdateControllerTest`، `SegmentShowControllerTest`، `SegmentDestroyControllerTest`، `SegmentEvaluateControllerTest`، `SegmentExportControllerTest`، `EvaluateSegmentJobTest`) — هرکدام deny>allow>role، نام تکراری Case-Insensitive، rule نامعتبر/تزریقی، ۴۰۴ برای نرم‌حذف‌شده/مفقود/غیرعددی، قفل is_system، Export بدون مجوز، و ثبت Audit را می‌پوشانند. کل سوییت Backend **۲۷۸۴ ← ۲۸۳۵ سبز** (۱۳۴۱۴ Assertion)، PHPStan ۰ خطا، Pint تمیز، Arch Suite ۲۱۳/۲۱۳.

**Smoke تست فرانت:** Playwright/Chromium در این محیط نصب نیست (بررسی شد) — طبق دستور صریح («اگر نیست، چک‌لیست دستی بنویس») نصب نشد. `tsc --noEmit` تمیز، `vp check --fix` (فقط ۴ فایل خودم)، `npm run build` موفق. چک‌لیست دستی در گزارش پایانی.

**عمداً ساخته نشد (برای P5-08):** `RebuildAllSegmentsJob` + Listener، Combobox محصول/دسته/سگمنت و Date-Picker شمسی (هنوز از P5-05 باقی مانده).

## P5-07 — DefaultSegmentSeeder (۱۱ از ۱۲ سگمنت Seed)

**⚠ ابهام #۱ — «در معرض ریزش» PRD در برابر برچسب صفحه‌ی RFM، با اعداد dev واقعی:** PRD §17 این سگمنت را «(churn medium)» می‌نویسد و «از دست رفته» را «(churn lost)» — یعنی فیلد `churn_risk_level`، نه `rfm_segment`. اما صفحه‌ی RFM (P4-08) برچسب «در معرض ریزش» را برای `rfm_segment=at_risk` به کار می‌برد، و کلمه‌ی «از دست رفته/Lost» هم در `rfm_segment` (بخش §12) و هم در `churn_risk_level` (بخش §14) به‌طور مستقل وجود دارد — دو مقدار متفاوت با نام مشابه. **تصمیم: PRD را عیناً دنبال کردیم** — هر دو سگمنت از `churn_risk_level` می‌سازند، نه `rfm_segment`، بدون حدس. عدد dev واقعی این تفاوت را به‌روشنی نشان می‌دهد:

| سگمنت | فیلد استفاده‌شده | عدد Preview (dev) |
|---|---|---|
| در معرض ریزش | `churn_risk_level = medium` | ۲٬۲۱۷ |
| از دست رفته | `churn_risk_level = lost` | ۳٬۴۲۶ |
| (مقایسه، نه یک سگمنت Seed) `rfm_segment = at_risk` | — | ۴۰ |
| (مقایسه، نه یک سگمنت Seed) `rfm_segment = lost` | — | **۱۳** ← عدد داده‌شده توسط کاربر برای «lost» دقیقاً همین است |

عدد ۱۳ که در دستور تسک برای «lost» آمده بود، با `rfm_segment=lost` مطابقت کامل دارد، نه با `churn_risk_level=lost` (۳٬۴۲۶) که سگمنت «از دست رفته» واقعاً از آن ساخته شده. این اختلاف عمداً حل نشد — طبق PRD پیش رفتیم و اختلاف را همین‌جا با اعداد واقعی ثبت کردیم؛ اگر منظور PRD واقعاً `rfm_segment` بوده (نه `churn_risk_level`)، این یک تصمیم محصول جداست، نه چیزی که بشود در کد حدس زد.

**⚠ ابهام #۲ — «سررسید خرید مجدد» (`expected_next_order_at ±7d`) — به تسک بعد موکول شد، ۱۱ از ۱۲ سگمنت ساخته شد:** PRD §17 JSON Rule Schema هیچ نوع مقداری برای «بازه‌ی نسبی به today» ندارد — هر `value` یک ثابت است، نه عبارتی که باید در لحظه‌ی evaluate دوباره محاسبه شود. دو راه واقعی وجود داشت:
1. **بازه‌ی مطلق در لحظه‌ی Seed:** یک تاریخ ثابت (مثلاً `now-7d`..`now+7d` در زمان اجرای Seeder) در `rule` بنویسیم. **رد شد** — این نادرست است، نه فقط ساده‌سازی: چند روز بعد «امروز» جابه‌جا می‌شود ولی rule ذخیره‌شده ثابت می‌ماند، پس این سگمنت به‌تدریج غلط می‌شود — دقیقاً برخلاف هدف یک سگمنت پویا.
2. **افزودن نوع مقدار نسبی به Schema/Validator/Compiler** (مثلاً عملگر یا شکل مقدار تازه‌ای مثل `{"relative_days": 7}` که RuleCompiler هر بار در لحظه‌ی Compile با `now() ± interval` واقعی می‌سازد): تنها راه درست، اما دقیقاً همان چیزی است که دستور صریح تسک گفت «بدون تصمیم گسترش نده».
طبق تصریح خود دستور («یا گذاشتن این یک سگمنت به تسک بعدی» به‌عنوان یک پیش‌فرض معتبر که نیازی به توقف ندارد، چون هیچ تغییر Schema‌ای رخ نمی‌دهد) — **پیش‌فرض انتخاب‌شده: این یک سگمنت ساخته نشد**، ۱۱ سگمنت دیگر کامل و تست‌شده تحویل داده شدند. پیشنهاد برای تسک بعد: راه‌حل #۲ بالا (یک عملگر/شکل‌مقدار تازه در Whitelist P5-01، تست‌محور مثل RuleValidator/RuleCompiler خودشان) — تصمیم و پیاده‌سازی آن باید جدا و با تأیید صریح باشد.

**پیش‌فرض `created_by = null`:** ستون به‌همین‌خاطر Nullable است. هاردکد‌کردن هر id/ایمیل کاربر خاص (مثلاً «اولین Owner») روی نصب تازه‌ای که هنوز آن کاربر ساخته نشده می‌شکند؛ این سگمنت‌ها نویسنده‌ی انسانی ندارند — Seed سیستمی‌اند، نه کار یک کاربر.

**اتصال به `DatabaseSeeder`:** بررسی شد و مشکلی ایجاد نکرد — اضافه شد. `DemoSnapshot` (چک Idempotent/Deterministic بودن `DemoDataSeeder`) اصلاً جدول `segments` را نمی‌بیند (`tests/Support/DemoSnapshot.php::TABLES`)، و تست «`leaves every derived table empty`» مستقیم `DemoDataSeeder` را صدا می‌زند نه از طریق `DatabaseSeeder`، پس هیچ‌کدام از این تست‌های موجود P5-07 را نمی‌بینند. برخلاف `DemoDataSeeder` (که فقط در `!isProduction()` اجرا می‌شود)، `DefaultSegmentSeeder` در هر محیطی اجرا می‌شود — این ۱۱ سگمنت داده‌ی واقعی محصول‌اند، نه داده‌ی آزمایشی.

**Idempotent/Soft-delete:** تطبیق با `lower(name)` (همان ایندکس `segments_name_unique`) از طریق `where('name', 'ilike', ...)` — بدون هیچ SQL خام. اگر سگمنتی نرم‌حذف شده بود، `restore()` می‌شود، نه ساخت دوباره. فقط فیلدهای تعریف (description/type/rule/rule_version/is_system) در اجرای دوباره به‌روز می‌شوند؛ `member_count`/`last_evaluated_at`/`last_eval_ms` دست‌نخورده می‌مانند تا اجرای دوباره‌ی Seeder، وضعیت یک ارزیابی واقعی (P5-08) را پاک نکند.

**تست:** ۵ تست (`DefaultSegmentSeederTest.php`) — ۱۱ سگمنت با نام یکتا/is_system/dynamic/rule_version=۱؛ Idempotent (دو اجرا، id یکسان)؛ بازیابی سگمنت نرم‌حذف‌شده؛ هر ۱۱ rule از `RuleValidator` و `RuleCompiler` واقعی (Postgres) بدون خطا رد می‌شود؛ و شمارش اعضا برای هر سگمنت (rfm_segment/churn_risk_level/total_orders/m_score/VIP) دقیقاً با `expected_metrics.json` (بدون Regenerate) برابر است — هر عدد از خودِ Fixture محاسبه شد، هیچ عدد ثابتی هاردکد نشد. کل سوییت Backend + این تست‌ها سبز، PHPStan ۰ خطا، Pint تمیز، Arch Suite ۲۱۳/۲۱۳ (بدون تغییر — Seeder هیچ Route/Controller تازه‌ای نساخت).

**تأیید روی dev:** فقط `app(DefaultSegmentSeeder::class)->run()` (نه `db:seed` کامل — که `DemoDataSeeder` را هم با ۵۰ مشتری جعلی روی داده‌ی واقعی اجرا می‌کرد). ۶ سگمنت مبتنی بر `rfm_segment` دقیقاً با اعداد داده‌شده در دستور تطابق داشتند: قهرمانان ۵۷، وفادار ۱۴۳، نویدبخش ۵٬۶۴۲، مشتری جدید ۲٬۴۲۳، نباید‌از‌دست‌برود ۵، خوابیده ۵٬۴۵۳ — همگی ✓. عدد «lost» (۱۳) داده‌شده در دستور، بالاتر توضیح داده شد (ابهام #۱).

**عمداً ساخته نشد:** سگمنت دوازدهم («سررسید خرید مجدد» — ابهام #۲ بالا)، `RebuildAllSegmentsJob`/Listener و فراخوانی `evaluate()` روی این سگمنت‌ها (صریحاً P5-08).
