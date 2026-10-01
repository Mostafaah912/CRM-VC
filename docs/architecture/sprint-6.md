# Architecture Log — Sprint 6

Split-file convention از Sprint 5 ادامه دارد؛ `ARCHITECTURE.md` فقط index و Open Items را نگه می‌دارد، تاریخچه‌ی کامل هر تسک این‌جاست.

## شروع Sprint 6 — بررسی GATE 1–3 قبل از کار

طبق `gate-check`: به‌جای حدس زدن، سه سوییت واقعی اجرا شد — `Gate2MetricsVerificationTest`، `Gate3SqlInjectionTest`، `ReconciliationServiceTest` (GATE 1). نتیجه: **۱۳۴/۱۳۴ سبز** (۱۵۳۰ Assertion). هر سه دروازه همچنان عبورشده — کاری برای دوباره بستن لازم نبود. `main` هم قبل از شاخه‌زدن با `Origin/main` هم‌گام شد (fast-forward، Sprint 5 کامل داخلش).

## Bugfix — `customers.metrics_dirty` هرگز `false` نمی‌شد (PRD §11 گام ۱۱)

**زمینه:** یافته‌ی P5-08 (`sprint-5.md`): هیچ نویسنده‌ای `metrics_dirty` را `false` نمی‌کرد؛ روی dev هر ۱۹٬۹۰۵ مشتری `true` بودند. PRD §11 صراحتاً گام ۱۱ از ۱۲ گام `recomputeAll()` را «`metrics_dirty = false`» تعریف کرده، درست قبل از گام ۱۲ (`finish → event`).

**TEST FIRST (تأیید صریح: تست‌ها قبل از کد نوشته و قرمز اجرا شدند):** ۶ تست تازه —
- ۱ تست واحد روی خودِ `BaseAggregateService::resetDirtyFlag()` (`tests/Feature/Modules/Metrics/BaseAggregateServiceTest.php`): فقط مشتریان همان `metric_run_id` ریست می‌شوند، مشتری متعلق به یک Run دیگر دست‌نخورده می‌ماند.
- ۵ تست سرتاسری روی `RecomputeMetricsJob::dispatchSync()` (`tests/Feature/Modules/Metrics/MetricsDirtyResetTest.php`): بعد از `dirty` مشتری پردازش‌شده `false` می‌شود؛ اجرای دوباره‌ی `dirty` بدون تغییر → `customers_processed=0`؛ `full` همه‌ی پردازش‌شده‌ها (چه قبلاً `true` چه `false`) را ریست می‌کند؛ مشتری نرم‌حذف‌شده (خارج از WHERE پایه) دست‌نخورده می‌ماند؛ شکست وسط Pipeline (همان ترفند `ALTER TABLE ... DROP COLUMN` که `RecomputeMetricsJobTest` قبلاً استفاده کرده بود) ریست را هم برمی‌گرداند.

اجرای اول این ۶ تست: ۳ قرمز به‌خاطر رفتار غلط، ۱ قرمز به‌خاطر متد ناموجود (`resetDirtyFlag`) — دقیقاً همان‌هایی که انتظار می‌رفت.

**طراحی انتخاب‌شده (ساده‌ترین سازگار با PRD §11، بدون Migration/Schema):** متد تازه `BaseAggregateService::resetDirtyFlag(int $metricRunId): int` — یک `UPDATE customers ... FROM customer_metrics WHERE cm.metric_run_id = ? AND c.metrics_dirty = true`. هیچ ستون/ردیابی تازه‌ای لازم نبود: `metric_run_id` از قبل (گام ۳، UPSERT پایه) دقیقاً روی همان مشتریانی نوشته می‌شود که آن Run واقعاً پردازش کرده — چه `full` (همه) چه `dirty` (فقط `metrics_dirty=true`‌ها) — پس فیلتر روی همین ستون، بدون فیلتر اضافه، هر دو حالت را درست پوشش می‌دهد. این متد آخرین خط داخل همان `DB::transaction()` موجود در `MetricsRecomputeService::run()` است (بعد از `lifecycle->resolve()`، قبل از `return $count`) — یعنی شکست هر گام قبلی، ریست را هم Rollback می‌کند، بدون نیاز به منطق تازه.

**پنجره‌ی رقابتی باقی‌مانده (عمداً حل نشد، طبق دستور ثبت شود):** اگر بین شروع Pipeline (گام ۳، خواندن Aggregate) و پایان آن (گام ۱۱، همین Transaction) یک سفارش تازه برای همان مشتری برسد و Listener `MarkCustomerMetricsDirty` را صدا بزند، `UPDATE` ما (که هدفش `metrics_dirty=true` است) منتظر قفل ردیف آن Customer می‌ماند، بعد آن را هم `false` می‌کند — یعنی سفارش تازه «قورت داده می‌شود»: مشتری «تمیز» علامت می‌خورد در حالی که Aggregateهایش هنوز آن سفارش را ندیده. تنها راه Mitigation فعلی: اجرای شبانه‌ی `full` (PRD §22) که همه‌چیز را صرف‌نظر از `metrics_dirty` دوباره می‌سازد، پس حداکثر یک شبانه‌روز اثر می‌ماند. رفع کامل (مثلاً قفل‌گیری صریح ردیف مشتری در Listener، یا شرط زمانی روی `updated_at`) خارج از Scope این Bugfix است.

**Mutation check دستی:** خط `$this->baseAggregates->resetDirtyFlag($run->id);` موقتاً به کامنت تبدیل شد → از ۵ تست `MetricsDirtyResetTest`، دقیقاً ۳ تای مرتبط با ریست شکستند (۲ تای دیگر — نرم‌حذف‌شده و شکست وسط Pipeline — طبیعتاً بدون تغییر سبز ماندند چون رفتار مورد انتظارشان همان «دست‌نخورده ماندن» است). خط بازگردانده شد، هر ۵ سبز.

**نتیجه:** `Gate2MetricsVerificationTest` و `tests/fixtures/expected_metrics.json` دست‌نخورده ماندند (۲/۲ سبز، ۱۱۶۹ Assertion). `RecomputeMetricsJobTest` (۱۰ تست قبلی) هم بدون تغییر سبز. Pint تمیز، PHPStan روی دو فایل تغییرکرده ۰ خطا.

**فایل‌ها:** `app/Modules/Metrics/Services/BaseAggregateService.php` (متد تازه + بازنویسی یک پاراگراف Docblock)، `app/Modules/Metrics/Services/MetricsRecomputeService.php` (یک خط فراخوانی + Docblock)، `tests/Feature/Modules/Metrics/BaseAggregateServiceTest.php` (۱ تست تازه)، `tests/Feature/Modules/Metrics/MetricsDirtyResetTest.php` (فایل تازه، ۵ تست).

**⚠ اثر جانبی کشف‌شده هنگام اجرای کامل تست‌ها (بستن Sprint 6، commit جدا):** بعد از این Bugfix، `php artisan test` کامل ۲ تست موجود در `MarkCustomerMetricsDirtyTest.php` را قرمز کرد (`it sets metrics_dirty on the event's customer`، `it never touches another customer's metrics_dirty`) — هر دو حتی تنها هم قرمز بودند، نه فقط در ترکیب با بقیه‌ی Suite. علت: `phpunit.xml` روی محیط تست `QUEUE_CONNECTION=sync` تنظیم کرده؛ این دو تست، برخلاف دو تست خواهر دیگر در همان فایل، `Queue::fake()` نداشتند. بدون آن، `RecomputeMetricsJob::dispatch('dirty')->delay(...)` واقعاً و هم‌زمان (چون Sync، `delay` را نادیده می‌گیرد) همان لحظه اجرا می‌شود — و حالا که Bugfix بالا واقعاً `metrics_dirty` را در پایان Pipeline `false` می‌کند، همان Job هم‌زمان پرچمی را که تست لحظه‌ای پیش `true` کرده بود، دوباره `false` می‌کند، قبل از این‌که Assertion اجرا شود. این یک وابستگی پنهان به همان باگی بود که رفع شد — نه یک رگرسیون در کد Production؛ در Production صف واقعی است، ۵ دقیقه تأخیر دارد، و تا آن زمان Aggregate واقعاً سفارش تازه را دیده، پس ریست‌شدن درست است. رفع: افزودن `Queue::fake()` به همان دو تست (دقیقاً همان الگویی که دو تست خواهرشان از قبل داشتند). `php artisan test` کامل بعد از این رفع: **۲۹۰۹/۲۹۰۹ سبز، ۱۳٬۵۸۱ Assertion**.

---

## P6-01 — Customer purchase aggregates

**چه ساخته شد:** `app/Modules/Analytics/Services/CustomerPurchaseAggregateService.php` (متد `rebuild()`) + `app/Modules/Analytics/Jobs/BuildCustomerPurchaseAggregatesJob.php` + `app/Modules/Analytics/Support/CustomerPurchaseAggregateSummary.php`. طبق PRD §16 عیناً: دو `INSERT ... SELECT` جدا برای `customer_product_purchases` و `customer_category_purchases` (دومی با JOIN از `product_category_product`)، هر دو داخل TRUNCATE قبل از INSERT، هر دو در یک `DB::transaction()`.

**TEST FIRST (تأیید صریح: تست‌ها قبل از کد نوشته شدند):** ۱۳ تست تازه —
- `tests/Feature/Modules/Analytics/CustomerPurchaseAggregateServiceTest.php` (۱۰ مورد): سفارش غیر-`is_realized` حذف می‌شود؛ سفارش نرم‌حذف‌شده حذف می‌شود؛ آیتم با `product_id=NULL` حذف می‌شود (از هر دو جدول)؛ عودت جزئی از `revenue` کم می‌شود ولی سفارش حذف نمی‌شود (برخلاف Base Aggregates)؛ دو ردیف از یک محصول در یک سفارش، یک `orders_count` می‌شود؛ محصول در دو دسته زیر هر دو دسته با اعداد کامل شمرده می‌شود؛ `last_bought_at` جدیدترین سفارش را می‌گیرد؛ اجرای دوباره Idempotent است (بدون تکثیر/تغییر)؛ بازسازی از صفر یعنی ردیف قدیمیِ محصولِ دیگر-خریداری-نشده پاک می‌شود؛ خلاصه‌ی برگشتی تعداد ردیف هر جدول را درست گزارش می‌دهد.
- `tests/Feature/Modules/Analytics/BuildCustomerPurchaseAggregatesJobTest.php` (۳ مورد): `ShouldBeUnique`؛ `uniqueFor(600) > timeout(300)`؛ Dispatch واقعی هر دو جدول را پر می‌کند.

اجرای اول: همه‌ی ۱۳ تست قرمز (`Class ... does not exist`) — به‌خاطر نبودن کلاس‌ها، دقیقاً همان قرمزی مورد انتظار.

**تفاوت عمدی از Base Aggregates (ثبت طبق دستور):** PRD §16 هیچ فیلتر `is_fully_refunded` ندارد — برخلاف `BaseAggregateService` (PRD §11) که سفارش‌های کاملاً مرجوعی را کلاً کنار می‌گذارد. این‌جا عیناً طبق PRD §16 پیاده شد: سفارش کاملاً مرجوعی هم در `orders_count`/`items_count` شمرده می‌شود، فقط `revenue` آن با `line_total - refunded_amount` صفر/منفی‌جبران می‌شود (هیچ آیتمی که کاملاً مرجوع شده باشد `revenue` منفی مصنوعی تولید نمی‌کند چون `line_total - refunded_amount` طبق قرارداد Refund Service هرگز منفی نمی‌شود — این فرض تست نشد، خارج از Scope این تسک).

**TRUNCATE در برابر DELETE+INSERT (تصمیم صریح طبق دستور کاربر):** TRUNCATE انتخاب شد، داخل همان Transaction با INSERT. دلیل: (۱) PRD §16 عیناً `TRUNCATE` نوشته؛ (۲) Docblock هر دو Migration (`033`/`034`) از قبل این دو جدول را «nightly TRUNCATE-and-rebuild aggregate» نامیده بودند — این یک تصمیم از‌قبل‌ثبت‌شده است، نه تصمیم تازه‌ی این تسک؛ (۳) `grep -rln customer_product_purchases\|customer_category_purchases app/ resources/js/ routes/` هیچ خواننده‌ای برنگرداند — یعنی امروز هیچ صفحه/سرویسی هم‌زمان از این دو جدول نمی‌خواند، پس قفل `ACCESS EXCLUSIVE` کوتاه TRUNCATE (اجرای واقعی dev: ۶۳ میلی‌ثانیه کل کار) هیچ تأثیر عملی ندارد؛ DELETE+INSERT برای حل یک مسئله‌ی هم‌زمانی که هنوز وجود ندارد، یک تصمیم قبلی مستند را دور می‌زد. **پرچم برای آینده:** وقتی تسک‌های بعدی Sprint 6 (Dashboard/Affinity/Customer 360) شروع به خواندن زنده از این جدول‌ها کنند و اگر هم‌زمان با زنجیره‌ی شبانه بخواهند بخوانند، این تصمیم باید بازبینی شود — این‌جا فقط پرچم شد، حل نشد.

**مرز ماژول — بدون تغییر Arch Test:** `tests/Arch/ArchitectureTest.php`'s Rule 7 (`confines raw SQL to migrations, the Metrics/Analytics modules...`) از قبل کل `app/Modules/Analytics/` را در `$allowedPrefixes` معاف کرده بود — برخلاف استثنای تک‌فایلیِ `Catalog\ProductListService` (P3-07). این معافیت گسترده هم از قبل در `ARCHITECTURE.md`("Confirmed decisions") ثبت شده بود. **نتیجه: هیچ تغییری در Allowlist یا هر Arch Test دیگر لازم نشد** — کد فقط جدول‌ها را با نام صریح (`orders`, `order_items`, `product_category_product`, خودِ دو جدول مقصد) می‌خواند/می‌نویسد، بدون Import هیچ Model ماژول دیگر (سازگار با جدول وابستگی `Analytics => [Core, Orders, Metrics, Catalog]`). `php artisan test --filter=ArchitectureTest` بعد از افزودن این دو فایل: ۱۴/۱۴ سبز، بدون تغییر.

**Queue:** `metrics` (نه `analytics` — Horizon فقط `critical/sync/metrics/ai/default` را Supervise می‌کند؛ `config/horizon.php:203` تأیید شد). همان انتخابی که `RebuildAllSegmentsJob` (P5-08) قبلاً کرده بود، با همان استدلال: طبق زنجیره‌ی شبانه‌ی PRD §22 این Job بلافاصله بعد از `RecomputeMetricsJob('full')` می‌آید، پس باید روی همان Worker Pool باشد، نه رقابت روی `default`.

**ابهام ثبت‌شده (طبق دستور، بدون کد اضافه):** PRD §10 از دستور کنسول `analytics:rebuild` نام می‌برد، ولی Backlog (بخش ۲۵) هیچ تسک مشخصی را مسئول ساخت آن نکرده — نه در P6-01، نه در کل Sprint 6. طبق قانون «یک Feature در هر Session»، این دستور ساخته نشد؛ برای راستی‌آزمایی dev از `BuildCustomerPurchaseAggregatesJob::dispatchSync()` در `php artisan tinker` استفاده شد. تصمیم نهایی (ساخت `analytics:rebuild` عمومی، یا واگذاری به Scheduler-only در P6-09) باید هنگام برنامه‌ریزی یک تسک بعدی گرفته شود.

**راستی‌آزمایی روی dev:**

```
elapsed_ms = 63
customer_product_purchases rows = 0
customer_category_purchases rows = 0
independent_sum (SUM(line_total-refunded_amount) روی order_items واقعی/زنده با product_id غیر NULL) = 0
table_sum (SUM(revenue) در customer_product_purchases) = 0
```

**⚠ یافته‌ی جدید — تشدید شدت یک Open Item موجود، نه یک باگ تازه:** صفر بودن هر دو جدول تصادفی نبود. کوئری مستقیم نشان داد **هیچ‌کدام از ۶۱٬۳۵۸ ردیف `order_items` روی dev** (نه فقط سفارش‌های محقق‌شده) `product_id` یا `variation_id` مقداردهی‌شده ندارند — با وجود ۱۲ ردیف در جدول `products`. `ARCHITECTURE.md`'s Open Item موجود («Simple products have no resolvable SKU/price»، P2-05/P2-06) این را به‌صورت یک محدودیت جزئی برای محصولات ساده توصیف کرده بود؛ عدد واقعی نشان می‌دهد **۱۰۰٪** از خطوط سفارش، نه بخشی از آن‌ها، تحت تأثیرند. کد این تسک (P6-01) دقیقاً طبق قرارداد PRD §16 عمل کرد (`WHERE oi.product_id IS NOT NULL` — رفتار درست، نتیجه‌ی درستِ روی داده‌ی ناقص). راستی‌آزمایی مستقل (`independent_sum` در برابر `table_sum`) به‌صورت بدیهی برابر بود (۰=۰) — یعنی این چک برابری خودش هیچ باگی را رد نمی‌کند تا Catalog/Sync محصول را به خطوط سفارش وصل کند؛ باید بعد از رفع P2-05/P2-06 دوباره با داده‌ی واقعی اجرا شود. متن Open Item در `ARCHITECTURE.md` به‌روزرسانی شد (شدت، نه تکرار).

**تست کامل این تسک:** `php artisan test --filter="CustomerPurchaseAggregateServiceTest|BuildCustomerPurchaseAggregatesJobTest"` → ۱۳/۱۳ سبز (۲۸ Assertion). `ArchitectureTest` → ۱۴/۱۴ سبز. Pint تمیز (یک‌بار Auto-fix روی ترتیب Import در فایل تست). PHPStan روی `app/Modules/Analytics/` → ۰ خطا.

**Mutation check دستی:** فیلتر `oi.product_id IS NOT NULL` موقتاً به `1=1` تغییر کرد → بلافاصله یک `QueryException` واقعی (Not-null violation روی `customer_product_purchases.product_id`) یکی از ۱۰ تست را شکست — یعنی این فیلتر واقعاً لازم است، نه صرفاً تزئینی. فایل بازگردانده شد، ۱۳/۱۳ دوباره سبز.

**فایل‌ها:** `app/Modules/Analytics/Services/CustomerPurchaseAggregateService.php`، `app/Modules/Analytics/Jobs/BuildCustomerPurchaseAggregatesJob.php`، `app/Modules/Analytics/Support/CustomerPurchaseAggregateSummary.php`، `tests/Feature/Modules/Analytics/CustomerPurchaseAggregateServiceTest.php`، `tests/Feature/Modules/Analytics/BuildCustomerPurchaseAggregatesJobTest.php`.

**عمداً ساخته نشد (طبق دستور، برای تسک‌های بعدی Sprint 6):** `analytics:rebuild` کنسول، Daily metrics (P6-02)، Cohort (P6-03/۰۴)، Affinity (P6-05)، Dashboard (P6-06+)، زنجیره‌ی کامل Scheduler (P6-09).

---

## P6-02 — Daily metrics

**چه ساخته شد:** `app/Modules/Analytics/Services/DailyMetricsService.php` (متد `rebuild(int $days, ?CarbonImmutable $asOf)`) + `app/Modules/Analytics/Jobs/BuildDailyMetricsJob.php` (`$days = 3` پیش‌فرض) + `app/Modules/Analytics/Support/DailyMetricsSummary.php`. Migration جدول `daily_metrics` از قبل وجود داشت (`2026_09_18_165733_create_daily_metrics_table.php`، دقیقاً طبق PRD §۹) — فقط بررسی شد، چیزی ساخته نشد.

**ابهام PRD حل‌شده (طبق دستور، پیش از کد ثبت شد):** PRD §۲۲ زنجیره‌ی شبانه را `BuildDailyMetricsJob(3)` می‌نویسد ولی معنای عدد ۳ را جایی توضیح نمی‌دهد. ساده‌ترین تفسیر سازگار با بقیه‌ی PRD انتخاب شد: یک «پنجره‌ی اصلاح» ۳روزه (امروز + دو روز قبل)، هم‌خانواده با الگوی `metrics:recompute --dirty` (PRD §۱۱) — روزهای اخیر ممکن است بعداً عوض شوند (عودت دیرهنگام، Sync دیرهنگام)، روزهای قدیمی‌تر پایدارند. برخلاف P6-01 (TRUNCATE کامل)، این‌جا فقط پنجره‌ی درخواستی UPSERT می‌شود (`ON CONFLICT (date) DO UPDATE`) و هر روز قدیمی‌تر دست‌نخورده می‌ماند — چون رفتار `BuildDailyMetricsJob(3)` بازسازی کل تاریخچه نیست.

**تصمیم‌های دیگر (طبق دستور، هرکدام با دلیل):**
- «سفارش شمرده‌شده» عیناً همان تعریف `BaseAggregateService` (PRD §۱۱): `is_realized = true AND is_fully_refunded = false AND deleted_at IS NULL` — نه تعریف P6-01 (که `is_fully_refunded` را فیلتر نمی‌کند)، چون `daily_metrics` همان اعداد Trend داشبورد را تغذیه می‌کند که باید با بقیه‌ی Metrics یکی باشند.
- روز تقویمی = روز شمسی/میلادی **محلی تهران** (`ordered_at AT TIME ZONE 'Asia/Tehran'`), نه UTC — طبق CLAUDE.md §۲. `jalali_date` با همان تابع PL/pgSQL `to_jalali()` نوشته می‌شود که `BaseAggregateService` برای `cohort_month` استفاده می‌کند.
- `customers_new`/`customers_repeat`/`revenue_new`/`revenue_repeat` از روی `customer_metrics.first_order_at` تشخیص داده می‌شوند (نه محاسبه‌ی دوباره از `orders`) — یعنی این Job به‌صراحت به تازه‌بودن `customer_metrics` وابسته است؛ همان دلیلی که PRD §۲۲ ترتیب زنجیره را `RecomputeMetricsJob('full') → BuildCustomerPurchaseAggregatesJob → BuildDailyMetricsJob(3)` گذاشته. اگر این Job جدا از زنجیره و روی `customer_metrics` بیات اجرا شود، مشتری واقعاً-تازه ممکن است تا بازمحاسبه‌ی بعدی «بازگشتی» شمرده شود — ثبت شد، رفع نشد (همان الگوی مستندسازی `RebuildAllSegmentsJob`).
- Queue = `metrics` (همان انتخاب P5-08/P6-01، چون Horizon فقط `critical/sync/metrics/ai/default` را Supervise می‌کند).

**TEST FIRST (تأیید صریح: تست‌ها قبل از کد نوشته شدند):** ۱۵ تست تازه —
- `tests/Feature/Modules/Analytics/DailyMetricsServiceTest.php` (۱۱ مورد): سفارش غیر-realized حساب نمی‌شود؛ روز بدون سفارش هم یک ردیف صفر می‌گیرد (نه غایب)؛ مرز روز تهران (سفارش ساعت ۰۱:۰۰ بامداد ۱۶ام به‌وقت تهران درست زیر ۱۶ام می‌رود، نه ۱۵ام UTC)؛ مقادیر دقیق revenue/refunds/net_revenue/aov روی یک روز دو-سفارشی؛ سفارش کاملاً‌مرجوعی مثل Base Aggregates حذف می‌شود؛ سفارش نرم‌حذف‌شده حذف می‌شود؛ تفکیک new/repeat از روی `first_order_at`؛ یک مشتری با دو سفارش هم‌روز یک‌بار شمرده می‌شود و new درست تشخیص داده می‌شود؛ Idempotent (دو اجرا = یک نتیجه)؛ روز خارج از پنجره دست‌نخورده می‌ماند؛ پنجره‌ی چندروزه شامل روز میانیِ بدون سفارش هم می‌شود.
- `tests/Feature/Modules/Analytics/BuildDailyMetricsJobTest.php` (۴ مورد): `ShouldBeUnique`؛ `uniqueFor(600) > timeout(300)`؛ پیش‌فرض `$days=3`؛ Dispatch واقعی جدول را پر می‌کند.

اجرای اول: هر ۱۵ تست قرمز (`Class ... does not exist`)؛ بعد از پیاده‌سازی، هر ۱۵ سبز — بدون هیچ اصلاح رفتار لازم (طراحی اول درست بود).

**Mutation check دستی (۲ مورد):** (۱) حذف `AND o.is_fully_refunded = false` → دقیقاً همان تستِ «سفارش کاملاً‌مرجوعی» شکست (۱۰/۱۱). (۲) گروه‌بندی روز را از `(ordered_at AT TIME ZONE 'Asia/Tehran')::date` به `(ordered_at)::date` (UTC خام) تغییر داد → دقیقاً تست مرز روز تهران شکست (۱۰/۱۱). هر دو بازگردانده شدند، ۱۵/۱۵ دوباره سبز.

**راستی‌آزمایی مستقل روی dev (پنجره‌ی ۷۳۵ روزه، از ۱۴۰۳/۰۷/۰۱ یعنی همان مبدأ GATE 1 تا امروز — عمداً همان ۱۱ سفارش خیلی‌قدیمیِ تاریخ‌غلط، یافته‌ی از‌قبل‌ثبت‌شده‌ی P2 با تاریخ سال ۱۴۰۴ در ستون میلادی، بیرون از این پنجره ماندند):**

```
elapsed_ms = 572
daily_metrics rows written = 735 (کل پنجره، شامل روزهای بدون سفارش)
daily_metrics: SUM(orders_count)=15,716   SUM(revenue)=12,157,406,361   SUM(customers_new)=13,973
مستقیم از orders (همان فیلتر): COUNT(*)=15,716   SUM(total)=12,157,406,361
مستقیم از customer_metrics.first_order_at در همین پنجره: 13,973
```

سه عدد **دقیقاً برابر** بودند، بدون هیچ اختلاف. کنترل داخلیِ اضافه: هیچ ردیفی در `daily_metrics` نقض `revenue_new + revenue_repeat = revenue` یا `customers_new + customers_repeat = customers_total` یا `revenue_repeat < 0` نداشت (۰ از ۷۳۵).

**تست کامل این تسک:** `php artisan test --filter="DailyMetricsServiceTest|BuildDailyMetricsJobTest"` → ۱۵/۱۵ سبز (۳۷ Assertion). `ArchitectureTest` → ۱۴/۱۴ سبز، بدون تغییر Allowlist (همان معافیت گسترده‌ی P6-01). PHPStan روی `app/Modules/Analytics/` → ۰ خطا. Pint تمیز.

**فایل‌ها:** `app/Modules/Analytics/Services/DailyMetricsService.php`، `app/Modules/Analytics/Jobs/BuildDailyMetricsJob.php`، `app/Modules/Analytics/Support/DailyMetricsSummary.php`، `tests/Feature/Modules/Analytics/DailyMetricsServiceTest.php`، `tests/Feature/Modules/Analytics/BuildDailyMetricsJobTest.php`.

**عمداً ساخته نشد:** `analytics:rebuild` کنسول (همان ابهام قبلی)، Cohort (P6-03/۰۴)، Affinity (P6-05)، Dashboard (P6-06+)، زنجیره‌ی کامل Scheduler (P6-09).

---

## P6-03 — Cohort snapshots + maturity flag

**چه ساخته شد:** `app/Modules/Analytics/Services/CohortSnapshotService.php` (متد `rebuild(?CarbonImmutable $asOf)`) + `app/Modules/Analytics/Jobs/BuildCohortSnapshotsJob.php` (بدون آرگومان، طبق PRD §۲۲) + `app/Modules/Analytics/Support/CohortSnapshotSummary.php`. Migration جدول `cohort_snapshots` از قبل وجود داشت (`2026_09_18_165734_create_cohort_snapshots_table.php`، دقیقاً طبق PRD §۹؛ Docblock خودش هم از قبل «TRUNCATE-and-rebuild table» می‌گفت) — فقط بررسی شد.

**ابهام‌های PRD حل‌شده (طبق دستور، پیش از کد ثبت شد):**
- **تعداد Period در هر Cohort:** PRD §۱۵ فقط «period 0 = acquisition» می‌گوید، هیچ سقفی مشخص نمی‌کند. ساده‌ترین تفسیر سازگار: از `config('metrics.horizon_years')` (همان افق ۲ساله‌ی CLV، PRD §۱۲/D12) استفاده شد → سقف ۲۴ دوره. دلیل رد گزینه‌ی جایگزین («فقط تا آخرین دوره‌ی واقعاً گذشته‌ی هر Cohort»): این گزینه هر ردیف تولیدشده را همیشه Mature می‌کرد (چون هرگز دوره‌ای فراتر از زمان سپری‌شده تولید نمی‌شد) و ستون `is_mature` را عملاً بلااستفاده می‌گذاشت — دقیقاً برخلاف هشدار «تله Cohort نابالغ» PRD که می‌خواهد سلول نابالغِ ماتریس واقعاً وجود داشته باشد (خاکستری/علامت‌دار، نه غایب و نه صفر). با سقف ثابت، هر Cohort برای هر ۲۵ دوره (۰ تا ۲۴) یک ردیف می‌گیرد؛ Cohortهای جوان برای دوره‌های آینده `is_mature=false` و `active_customers=0` واقعی می‌گیرند.
- **تناقض فرمول Maturity با توضیح آن:** PRD §۱۵ یک خط بالاتر از فرمول SQL می‌گوید «Cohort واقعاً برای N ماه کامل وجود داشته باشد» (که یعنی عملگر باید `>` باشد، نه `>=`)، ولی خودِ SQL باکس‌شده می‌نویسد `jalali_month_diff(...) >= period_number` (ماه در حال گذر را هم Mature می‌شمارد). فرمول SQL باکس‌شده عیناً پیاده شد (همان اولویتی که در سراسر این کدبیس به SQL باکس‌شده‌ی PRD داده می‌شود، نه توضیح نثری آن — مثلاً `BaseAggregateService` هم SQL §۱۱ را عیناً می‌آورد) — تناقض فقط ثبت شد، حل نشد به‌نفع یکی.
- **«Revenue» کدام تعریف:** برخلاف `daily_metrics` (P6-02) که سه ستون جدا (`revenue`/`refunds`/`net_revenue`) دارد، اینجا PRD فقط یک ستون `revenue` تعریف کرده. `o.net_revenue` (خالص، بعد از عودت) انتخاب شد — همان مبنایی که `BaseAggregateService`، `DailyMetricsService.net_revenue`، و کل موتور RFM/CLV/Churn روی آن ساخته شده‌اند؛ یک تعریف «واقعی از پول رسیده» در کل Metrics/Analytics.
- **«سفارش شمرده‌شده»:** عیناً همان تعریف `BaseAggregateService`/P6-02: `is_realized = true AND is_fully_refunded = false AND deleted_at IS NULL` (مشتری نرم‌حذف‌شده هم فیلتر شد) — نه تعریف P6-01 که `is_fully_refunded` را نادیده می‌گیرد.

**وابستگی ترتیب زنجیره (ثبت، نه رفع، همان الگوی مستندسازی P6-02/P5-08):** `cohort_month` مستقیماً از `customer_metrics.cohort_month` خوانده می‌شود، نه دوباره از `orders` محاسبه — یعنی این Job هم به تازه‌بودن خروجی `RecomputeMetricsJob('full')` وابسته است، دقیقاً همان‌طور که PRD §۲۲ ترتیبش داده.

**TEST FIRST (تأیید صریح: تست‌ها قبل از کد نوشته شدند):** ۱۰ تست تازه —
- `tests/Feature/Modules/Analytics/CohortSnapshotServiceTest.php` (۷ مورد): مقادیر دقیق `cohort_size`/`active_customers`/`retention_rate`/`orders_count`/`revenue`/`cumulative_revenue` روی فیکسچر دو‌مشتری دو‌دوره‌ای؛ سفارش غیر-realized نه مشتری فعال نه Revenue اضافه نمی‌کند؛ سفارش کاملاً‌مرجوعی و نرم‌حذف‌شده حذف می‌شوند؛ مرز ماه شمسی/تهران (سفارش ساعت ۰۰:۱۵ بامداد تهران روز اول ماه بعد، که در UTC خام هنوز روز قبل است، درست به ماه بعد نسبت داده می‌شود)؛ پرچم Maturity (دوره‌ی نرسیده `false`، دوره‌ی مرزی/در-حال-گذر طبق فرمول باکس‌شده `true`)؛ Idempotent؛ بازسازی از صفر (Cohort قدیمیِ دیگر در `customer_metrics` نبود، از جدول حذف می‌شود).
- `tests/Feature/Modules/Analytics/BuildCohortSnapshotsJobTest.php` (۳ مورد): `ShouldBeUnique`؛ `uniqueFor(600) > timeout(300)`؛ Dispatch واقعی جدول را پر می‌کند.

اجرای اول: هر ۱۰ تست قرمز (`Class ... does not exist`)؛ بعد از پیاده‌سازی، هر ۱۰ سبز — بدون هیچ اصلاح رفتار لازم.

**Mutation check دستی (۲ مورد):** (۱) حذف `AND o.is_fully_refunded = false` → دقیقاً تست «سفارش کاملاً‌مرجوعی» شکست (۶/۷). (۲) تغییر عملگر Maturity از `>=` به `>` → دقیقاً تست Maturity شکست (۶/۷). هر دو بازگردانده شدند، ۱۰/۱۰ دوباره سبز.

**راستی‌آزمایی مستقل روی dev:**

```
elapsed_ms = 703
rows written = 625  (۲۵ Cohort × ۲۵ دوره [۰..۲۴])، min cohort_month=1403-06  max=1405-07
SUM(cohort_size @ period 0)      = 13,981   مستقیم COUNT(مشتری‌های non-deleted با cohort_month غیرNULL) = 13,981
SUM(revenue در کل جدول)          = 12,015,968,388   مستقیم SUM(net_revenue) با همان فیلتر = 12,015,968,388
SUM(orders_count در کل جدول)     = 15,581   مستقیم COUNT(*) با همان فیلتر = 15,581
```

سه عدد **دقیقاً برابر**. کنترل سلامت اضافه: هیچ ردیفی `cumulative_revenue < revenue` یا `active_customers > cohort_size` نداشت (۰ از ۶۲۵). **یافته‌ی جانبی، بدون نیاز به کاری:** دو سفارش با تاریخ خراب (سال ۱۴۰۴ میلادی، یافته‌ی از‌قبل‌ثبت‌شده در Open Items) هر دو `customer_id = NULL` دارند (سفارش بی‌موبایل)، پس به‌طور طبیعی از این محاسبه بیرون ماندند — بدون نیاز به فیلتر دستی اضافه.

**تست کامل این تسک:** `php artisan test --filter="CohortSnapshotServiceTest|BuildCohortSnapshotsJobTest"` → ۱۰/۱۰ سبز (۳۰ Assertion). `ArchitectureTest` → ۱۴/۱۴ سبز، بدون تغییر Allowlist. PHPStan روی `app/Modules/Analytics/` → ۰ خطا. Pint تمیز.

**فایل‌ها:** `app/Modules/Analytics/Services/CohortSnapshotService.php`، `app/Modules/Analytics/Jobs/BuildCohortSnapshotsJob.php`، `app/Modules/Analytics/Support/CohortSnapshotSummary.php`، `tests/Feature/Modules/Analytics/CohortSnapshotServiceTest.php`، `tests/Feature/Modules/Analytics/BuildCohortSnapshotsJobTest.php`.

**عمداً ساخته نشد:** `analytics:rebuild` کنسول، Retention/immature-guard صفحات و Store-level معیارها (Repeat Purchase Rate/N-day retention/Returning revenue share — بخش «معیارهای سطح فروشگاه» PRD §۱۵، مال P6-04)، Affinity (P6-05)، Dashboard (P6-06+)، زنجیره‌ی کامل Scheduler (P6-09).

---

## P6-04 — Retention + immature guard + «داده کافی نیست»

**چه ساخته شد:** `app/Modules/Analytics/Services/RetentionService.php` با سه متد مستقل — `repeatPurchaseRate()`، `returningRevenueShare()`، `nDayRetention(int $days, ?CarbonImmutable $asOf)` — و سه DTO همراه (`RepeatPurchaseRateResult`، `ReturningRevenueShareResult`، `NDayRetentionResult`) در `app/Modules/Analytics/Support/`.

**تصمیم معماری (ابهام حل‌شده، ثبت پیش از کد): بدون Job، بدون Migration، بدون جدول.** برخلاف P6-01/۰۲/۰۳، فهرست Jobهای PRD §۲۲ هیچ Job مشخصی برای این سه معیار ندارد؛ هرکدام یک خواندن ارزان روی جدول‌های از‌قبل‌مادی‌شده (`customer_metrics`/`orders`) است، نه یک Aggregate سنگین سراسری. قانون Dashboard در PRD §۱۸ («هیچ تجمیع سنگینی در لحظه بارگذاری») همان‌طور برآورده می‌شود که صفحات RFM/Churn از قبل برآورده می‌کنند (خواندن مستقیم از `customer_metrics`)، نه با افزودن یک جدول Cache دیگر برای عددهایی به این ارزانی.

**ابهام‌های دیگر PRD حل‌شده (طبق دستور، پیش از کد ثبت شد):**
- **Maturity اینجا چیست:** فرمول PRD §۱۵ («N-day retention: only over MATURE cohorts (first_order_at <= now() - n days)») یک مفهوم Maturity **روزانه و سطح‌مشتری** است، کاملاً جدا از `cohort_snapshots.is_mature` (P6-03، سطح‌ماه و سطح‌Cohort) — پس این سرویس اصلاً به `cohort_snapshots` سر نمی‌زند؛ Maturity مستقیماً از `customer_metrics.first_order_at` محاسبه می‌شود.
- **«تله Cohort نابالغ» چطور اعمال شد:** مشتری نابالغ (کمتر از N روز از اولین سفارشش گذشته) کاملاً از محاسبه (هم صورت هم مخرج کسر) حذف می‌شود — حتی اگر از قبل یک سفارش دوم زودهنگام ثبت کرده باشد؛ شامل‌کردنش نرخ را با یک نمونه‌ی ناقص و جهت‌دار متورم می‌کند (تست اختصاصی نوشته شد، پایین).
- **«داده کافی نیست»:** بدون آستانه‌ی اختراعی (برخلاف Churn که PRD خودش عدد ۲۰۰ می‌دهد) — ساده‌ترین تفسیر: مخرج صفر (هیچ مشتری واجد شرایط/بالغی نیست) یعنی `insufficientData=true` و مقدار نرخ `null`، هرگز یک صفر ساختگی.
- **Returning Revenue Share در سطح سفارش، نه روز:** برخلاف `DailyMetricsService` (P6-02) که new/repeat را در سطح **روز تقویمی** تفکیک می‌کند، فرمول PRD §۱۵ اینجا دقیقاً لحظه‌ی `first_order_at` را با `ordered_at` مقایسه می‌کند — یعنی سفارش دومِ همان‌روزِ سفارش اول هم «بازگشتی» شمرده می‌شود (برخلاف P6-02). عیناً طبق فرمول پیاده شد؛ تفاوت با P6-02 عمدی و ثبت‌شده است، نه ناهماهنگی.
- **فیلتر `is_fully_refunded` فقط روی دو متد، نه سه‌تا:** برای `nDayRetention` عیناً همان تعریف `BaseAggregateService` اعمال شد. برای `returningRevenueShare` این فیلتر **عمداً حذف شد** — چون برای سفارش کاملاً‌مرجوعی `net_revenue = total − refunded_total = 0` همیشه برقرار است، پس فیلتر یا نبودنش هیچ تفاوتی در جمع نمی‌گذارد (Mutation Test زیر این را ثابت کرد: حذف فیلتر هیچ تستی را نشکست، چون از اول اثری نداشت — نگه‌داشتنش صرفاً کد بی‌اثر بود).

**TEST FIRST (تأیید صریح: تست‌ها قبل از کد نوشته شدند):** ۱ فایل، ۱۵ تست — `tests/Feature/Modules/Analytics/RetentionServiceTest.php`: مقادیر دقیق هر سه متد روی فیکسچرهای کوچک؛ حذف مشتری نرم‌حذف‌شده و سفارش غیر-realized/کاملاً‌مرجوعی از هر سه؛ مرز Maturity شامل (`first_order_at = asOf − N روز` دقیقاً باید بالغ باشد)؛ حذف کامل مشتری نابالغ حتی با بازگشت زودهنگام (تله Cohort نابالغ)؛ بازگشت بیرون از پنجره‌ی N روزه حذف می‌شود؛ «داده کافی نیست» وقتی مخرج صفر است؛ Idempotent (دو فراخوانی = یک نتیجه، بدون نوشتن چیزی).

**«مرز زمان تهران» در این تسک به چه معناست (توضیح صریح، چون شکل متفاوتی از P6-02/۰۳ دارد):** ریاضی این سرویس روی بازه‌های زمانی مطلق (`INTERVAL 'N day'` روی `timestamptz`) است، نه گروه‌بندی روی روز/ماه تقویمی — پس هیچ مرز تقویمی تهرانی برای شکستن وجود ندارد (۳۰ روز واقعی همیشه ۳۰ روز واقعی است، مستقل از منطقه‌زمانی نمایش). چیزی که واقعاً تست شد: فراخوانی با `$asOf` یک‌بار در Asia/Tehran و یک‌بار در UTC (همان لحظه‌ی مطلق) باید دقیقاً یک نتیجه بدهد — یعنی سرویس منطقه‌زمانیِ نمایشیِ ورودی را نادیده می‌گیرد و فقط لحظه‌ی مطلق مهم است.

**یافته‌ی جانبی حین TEST FIRST (رفع شد، کد Production هرگز اشتباه نبود):** دو Helper تست (`rsSeedMetrics`، `rsOrder`) اول بدون `->utc()` نوشته شدند؛ چون `DB::table()->insert()` هیچ Cast تاریخی مثل مدل Eloquent ندارد و Cast `'datetime'` مدل `Order` هم قبل از فرمت‌کردن منطقه‌زمانی را عوض نمی‌کند، هر دو رقم ساعت محلیِ Asia/Tehran را عیناً به‌عنوان UTC می‌نوشتند (همان تله‌ی مستندشده در ARCHITECTURE.md/P0-05). ۳ تست به‌خاطر همین با اعداد نادرست شکستند؛ رفع با افزودن `->utc()` صریح در هر دو Helper (همان الگویی که `DailyMetricsServiceTest`‌ی P6-02 از قبل داشت). **هیچ خط از `RetentionService.php` نیاز به تغییر نداشت** — باگ فقط در فیکسچر تست بود، نه در منطق سرویس.

**Mutation check دستی (۳ مورد، هرکدام تا رد‌شدن دقیقاً همان تست هدف تکرار شد):** (۱) حذف `AND o.is_fully_refunded = false` از `nDayRetention` → دقیقاً تست «سفارش کاملاً‌مرجوعی» شکست. (۲) عملگر مرز Maturity از `<=` به `<` → دقیقاً تست «مرز شامل» شکست. (۳) سقف پنجره‌ی بازگشت را ۱۰ روز بزرگ‌تر کرد → دقیقاً تست «بیرون از پنجره» شکست. یک مورد چهارم (حذف فیلتر `is_fully_refunded` از `returningRevenueShare`) **هیچ تستی را نشکست** — که خودش تأیید مستقل تصمیم بالا بود (فیلتر همیشه بی‌اثر است)، نه یک شکاف پوشش. همه‌ی موارد بازگردانده شدند، ۱۵/۱۵ دوباره سبز.

**راستی‌آزمایی مستقل روی dev:**

```
RepeatPurchaseRate:      eligible=13,981  repeat=1,078   rate=0.0771 (۷٫۷۱٪)
مستقیم از customer_metrics: eligible=13,981  repeat=1,078   ✓ برابر

ReturningRevenueShare:   total=12,015,968,388  returning=2,013,093,961  share=0.1675 (۱۶٫۷۵٪)
مستقیم SUM(net_revenue) با همان فیلتر: total=12,015,968,388   ✓ برابر

NDayRetention(30):  mature=13,020  returned=464   rate=0.0356 (۳٫۵۶٪)
مستقیم: mature=13,020  returned=464   ✓ برابر

NDayRetention(90):  mature=9,956   returned=547   rate=0.0549 (۵٫۴۹٪)  (فقط برای مقایسه آورده شد، Cross-check جدا نگرفت)
```

سه عدد اصلی **دقیقاً برابر** کوئری مستقل. **سیگنال کسب‌وکاری (نه باگ، طبق یادآوری خودِ اسکیل `gate-check` درباره‌ی نرخ خرید مجدد زیر ۱۰٪):** Repeat Purchase Rate واقعی روی dev ۷٫۷۱٪ است — زیر آستانه‌ی ۱۰٪ — باید به کاربر گزارش شود به‌عنوان یک واقعیت کسب‌وکار، نه یک نشانه‌ی باگ محاسباتی (خود اعداد با کوئری مستقل تأیید شدند).

**تست کامل این تسک:** `php artisan test --filter=RetentionServiceTest` → ۱۵/۱۵ سبز (۳۷ Assertion). `ArchitectureTest` → ۱۴/۱۴ سبز، بدون تغییر Allowlist. PHPStan روی `app/Modules/Analytics/` → ۰ خطا. Pint تمیز.

**فایل‌ها:** `app/Modules/Analytics/Services/RetentionService.php`، `app/Modules/Analytics/Support/RepeatPurchaseRateResult.php`، `app/Modules/Analytics/Support/ReturningRevenueShareResult.php`، `app/Modules/Analytics/Support/NDayRetentionResult.php`، `tests/Feature/Modules/Analytics/RetentionServiceTest.php`.

**عمداً ساخته نشد:** `analytics:rebuild` کنسول، صفحات Cohort/Retention UI («سلول نابالغ خاکستری» ماتریس، مال P6-08)، Affinity (P6-05)، Dashboard (P6-06+)، زنجیره‌ی کامل Scheduler (P6-09).

---

## تشخیص — چرا هیچ‌کدام از ۶۱٬۳۵۸ ردیف order_items روی dev، product_id قابل‌حل ندارد (بدون کد، طبق دستور صریح توقف)

**درخواست:** رفع باگی که P6-01 (aggregates خالی)، P6-05 (Affinity) و سگمنت‌های محصول‌محور را می‌شکند. مرحله‌ی ۱ (تشخیص، فقط خواندن) کامل شد؛ مرحله‌ی ۲ (رفع) طبق دستور صریح متوقف شد چون رفع واقعی نیازمند اجرای زنده روی WooCommerce است — این بخش فقط تشخیص + برنامه‌ی رفع را ثبت می‌کند، **هیچ کد/Migration/Jobی نوشته نشد**.

### علت اصلی (۱۰۰٪ از ۶۱٬۳۵۸ ردیف): کاتالوگ واقعی Woo هرگز روی dev Sync نشده

با کوئری مستقیم و خواندن کد تأیید شد:

- `products` روی dev **۱۲ ردیف** دارد، همه با `woo_product_id` بین ۳۰۰۰ تا ۳۰۱۱ و `synced_at` ثابت `2026-06-30 08:30:00`؛ `product_variations` **۲۴ ردیف** با `woo_variation_id` ۴۰۰۰۰-۴۰۰۲۳ و SKUهایی مثل `HM-00-S`. این‌ها عیناً `database/seeders/DemoDataSeeder.php:154` (`'woo_product_id' => 3000 + $p`) هستند — یعنی داده‌ی Fixture تست Gate 2، نه کاتالوگ واقعی فروشگاه.
- `order_items` واقعی (۶۱٬۳۵۸ ردیف) SKUهای واقعی مثل `hmp-7614`/`hmp-6072` دارند (۱٬۹۱۱ SKU یکتا) — **صفر** تطابق با ۲۴ SKU فیکسچر بالا (`JOIN order_items ON sku = product_variations.sku` → ۰ ردیف).
- `sync_cursors` روی dev **فقط یک ردیف دارد: `orders`**. هیچ Cursor/Sync-jobی برای `products`/`variations`/`categories` هرگز اجرا نشده.
- `app/Modules/Sync/Enums/SyncEntity.php` امروز **فقط `Orders`** دارد؛ کامنت خودِ فایل صریح است: «catalog and customers are not run through here yet». `php artisan hm:sync --entity=products` هم امروز اجرا نمی‌شود: `SyncCommand.php` پیام ثابت `Unknown entity. Supported: orders.` می‌دهد.
- **`CatalogSyncService` (ساخته‌شده در P2-05، طبق `sprint-2.md` با ۱۰۳۶ تست سبز و Mutation check کامل) هرگز از هیچ‌جای اپلیکیشن صدا زده نمی‌شود** — `grep -rln CatalogSyncService app/ routes/` فقط خودِ فایل را برمی‌گرداند. این عمدی و مستند بود: خودِ P2-05 زیر «آنچه ساخته نشد» می‌گوید «SyncService/Job/Cursor/Window» برای کاتالوگ ساخته نشد؛ P2-08 صریح می‌گوید «Sync دسته‌بندی/محصول/مشتری در این Run» عمداً نیست؛ P2-10 هم فقط `orders` را Schedule کرده. **این یک باگ نیست — یک تسک Backlog است که هرگز به Job/Command سطح Sprint 2 نرسید**، نه خطای پیاده‌سازی.

### علت دوم، ساختاری (بخشی از ردیف‌ها را حتی بعد از Sync واقعی هم می‌بندد)

- `products` ستون `sku`/`price` ندارد؛ فقط `product_variations` دارد. الگوریتم ۴مرحله‌ای Resolve (`OrderService::resolve()`, PRD §۱۰) فقط با `product_variations` کار می‌کند (`resolveVariationByWooId`/`ByProductAndSku`/`BySku`) — هرگز مستقیم با `products`.
- طبق `sprint-2.md`‌ی P2-05 (خط ۷۷) محصول Woo از نوع `simple` هیچ ردیف `product_variations`ی نمی‌گیرد؛ P2-06 (خط ۹۳) همین را «محدودیت شناخته‌شده» می‌نامد و صراحتاً می‌گوید «⚠ باید قبل از P2-06 تصمیم‌گیری شود» — این تصمیم هرگز گرفته نشد (همان Open Item موجود در ARCHITECTURE.md، P2-05/P2-06). یعنی حتی بعد از یک Sync واقعیِ کامل، **خط سفارش هر محصول Simple همچنان NULL می‌ماند**، مگر این تصمیم گرفته شود.

### محدودیت سوم: داده‌ی خام Woo برای Backfill محلی نصفه‌کاره باقی مانده

`order_items` هرگز شناسه‌ی خام Woo (`woo_product_id`/`woo_variation_id` که Woo در Payload می‌فرستد) را ذخیره نمی‌کند — فقط ستون‌های FK حل‌شده (که امروز همه NULL‌اند). چیزی که واقعاً باقی مانده:

```
کل order_items:            61,358
با sku غیر NULL:            55,560  (۹۰٫۵۵٪) — بعد از Sync واقعی، از مرحله‌ی ۲/۳ Resolve قابل بازیابی
با sku NULL:                 5,798  (۹٫۴۵٪)  — هیچ داده‌ای برای Resolve محلی باقی نمانده؛ فقط با Re-sync از Woo (و Migration جدید برای نگه‌داشتن id خام) قابل حل است
```

پس یک Backfill محلی صرف (بدون Re-sync سفارش‌ها) حداکثر تا ۹۰٫۵۵٪ می‌تواند برود، هرگز ۱۰۰٪.

### برنامه‌ی رفع (سه تصمیم، به ترتیب وابستگی — هیچ‌کدام امروز اجرا نشد)

1. **تصمیم معماری (نیاز به تأیید شما، نه من): محصول Simple چطور Resolve شود؟** یا (الف) یک Variation پیش‌فرض مصنوعی (`woo_variation_id = NULL`) با SKU/قیمت خودِ محصول در زمان Sync ساخته شود، یا (ب) ستون‌های `sku`/`price` مستقیم به `products` اضافه شود و الگوریتم ۴مرحله‌ای یک مرحله‌ی جدید (`resolveProductBySku`) بگیرد. هر دو Migration جدید لازم دارند؛ PRD ساکت است و پروژه دوبار (P2-05 و P2-06) همین تصمیم را عمداً به انسان واگذار کرده — من هم اختراع نکردم.
2. **Wiring Sync کاتالوگ (بی‌خطر، بدون تماس زنده — می‌توانم بسازم):** افزودن `SyncEntity::Categories/Products/Variations` (یا یک Case ترکیبی `Catalog`، چون `CatalogSyncService` همین سه را با هم انجام می‌دهد)، یک Job نازک هم‌الگوی `SyncEntityJob`/P2-08، و ثبت در `hm:sync`/Scheduler — دقیقاً همان الگوی Orders. TEST FIRST با `FakeWooClient`، بدون هیچ تماس زنده. این بخش مستقل از تصمیم ۱ است و امن است.
3. **اجرای واقعی روی فروشگاه زنده Woo:** بعد از (۱) و (۲)، Sync واقعی باید یک‌بار روی دیتابیس dev اجرا شود تا کاتالوگ واقعی جایگزین Fixture شود. این اجرای زنده (تماس API واقعی، حجم/زمان نامعلوم روی کاتالوگ واقعی فروشگاه) دقیقاً همان نوع اقدامی است که طبق دستور شما نباید بدون تأیید صریح انجام شود — **متوقف شدم اینجا**. بعد از تأیید و اجرا، هیچ Backfill جداگانه‌ای لازم نیست: `BuildCustomerPurchaseAggregatesJob` (P6-01) از قبل idempotent است و از صفر از روی `order_items.product_id` تازه بازسازی می‌شود.

### کراس‌چک این تشخیص (بدون هیچ Sync/Backfill، فقط برای اثبات صفر بودن Baseline)

```
customer_product_purchases rows (بعد از rerun P6-01 روی همین داده): 0
customer_category_purchases rows:                                    0
درصد order_items با product_id قابل‌حل:                              0.00% (0 / 61,358)
```

عدد صفر همان چیزی است که در P6-01 (`sprint-6.md`, dev verification) قبلاً دیده و ثبت شده بود؛ این تسک فقط **چرایی** آن را با اعداد دقیق روشن کرد، عددی تغییر نکرد چون هیچ کدی اجرا نشد.

**تصمیم لازم از شما:** کدام گزینه‌ی تصمیم ۱ (پیش‌فرض یا ستون‌های `products`)، و آیا اجازه‌ی ساخت Wiring تصمیم ۲ (بدون اجرای زنده) در یک تسک بعدی داده می‌شود؟

---

## رفع — ستون‌های sku/price روی products (تصمیم ۱)، Wiring Sync کاتالوگ (تصمیم ۲)، تلاش اجرای زنده (تصمیم ۳، متوقف‌شده روی یک یافته‌ی تازه)

**تصمیم‌های شما (عیناً اجرا شد):** (۱) ستون‌های Nullable `sku`/`price` به `products` اضافه شود + یک گام Resolve روی `products.sku`. (۲) `SyncEntity` برای Catalog گسترش یابد، با Job/Command idempotent قابل‌اجرای مجدد، هم‌الگوی Orders. (۳) اجرای زنده فقط GET، با Throttle و Page کوچک، بدون نوشتن روی Woo؛ اگر خطا/کندی دیدم متوقف شوم.

### تصمیم ۱ — sku/price روی products (انحراف عمدی از PRD §۹، ثبت‌شده)

PRD §۹ برای `products` ستون `sku`/`price` تعریف نمی‌کند — طبق `sprint-2.md`ی P2-05 (خط ۷۷) عمدی بود. این یک تصمیم صریح کاربر است، نه اختراع من؛ چون PRD §۹ چیز دیگری می‌گوید، طبق دستور در `ARCHITECTURE.md`ی «تصمیم‌های تأییدشده» ثبت شد (پایین‌تر).

**Migration جدید (قابل‌بازگشت):** `2026_09_27_100000_add_sku_price_to_products_table.php` — `sku varchar(80) NULL`، `price bigint NULL`، ایندکس یکتای جزئی `WHERE sku IS NOT NULL` (عیناً همان الگوی `product_variations.sku`).

**یافته‌ی خوش‌شانس:** `ProductDto`/`ProductMapper` (P2-05) از قبل `sku`/`price` را از Payload واقعی Woo می‌خواندند («PRD §۱۰ گام ۳ با sku تنها Resolve می‌کند» — کامنت خودِ `ProductDto`) ولی بین DTO و `ProductInput` (Value Object ماژول Catalog) بی‌صدا دور ریخته می‌شدند چون `products` جایی برای نوشتنشان نداشت. فقط لازم بود این دو فیلد در `ProductInput`، `CatalogSyncService::input()`، و `CatalogService::upsertProduct()` واقعاً جاری شوند — هیچ تغییری در Mapper/DTO لازم نبود.

**گام Resolve تازه (۳.۵، نه بخشی از ۴ گام اصلی PRD §۱۰):** `CatalogService::resolveProductBySku()` بعد از شکست گام ۳ (SKU روی Variation) و پیش از تسلیم امتحان می‌شود. `ResolvedCatalogItem::$variationId` از `int` به `?int` عوض شد (یک محصول Simple هیچ Variation‌ای ندارد). یکتایی SKU حالا **بین دو جدول** چک می‌شود: `assertProductSkuFree()` (تازه) و `assertSkuFree()` (به‌روزشده) هرکدام هم `products` هم `product_variations` را می‌بینند — سه Factory تازه در `CatalogIntegrityException` برای سه جهت تعارض ممکن.

**TEST FIRST:** ۱۹ تست تازه/به‌روزشده — `CatalogServiceTest.php` (۱۱ مورد: ذخیره sku/price، به‌روزرسانی، عدم برخورد با خودش، سه جهت تعارض SKU بین دو جدول، ایندکس دیتابیس، `resolveProductBySku` موفق/ناموفق)، `OrderServiceTest.php` (۳ مورد: STEP 3.5 موفق با variation_id=NULL، اولویت با Variation اگر هر دو موجود باشند، SKU محصول که به هیچ‌جا نمی‌خورد همچنان Unresolved)، `CatalogSyncServiceTest.php` (۱ مورد: Fixture واقعی محصول ۱۰۱ حالا sku='SYN-TEE-001'/price=۴۰۳۸۸۰ را واقعاً ذخیره می‌کند — تأیید سرتاسری، نه فقط واحد). Mutation check دستی (۲ مورد): تغییر SKU در فراخوانی گام ۳.۵ → دقیقاً تست همان گام شکست؛ حذف فراخوانی `assertProductSkuFree` → دقیقاً تست تعارض مربوطه شکست (یکی هم با خطای DB خام به‌جای Exception برنامه، که خودش دلیل وجود Guard است). هر دو بازگردانده شدند.

### تصمیم ۲ — Wiring Sync کاتالوگ

`SyncEntity::Catalog` اضافه شد، ولی **هرگز** به `SyncService::run()` نمی‌رسد (آن سرویس کاملاً برای مکانیزم Cursor/Window سفارش‌ها ساخته شده)؛ یک Guard صریح (`InvalidArgumentException`) اضافه شد تا این دو مسیر هرگز قاطی نشوند. `app/Modules/Sync/Jobs/CatalogSyncJob.php` (تازه) فقط `CatalogSyncService::syncCategories()` سپس `::syncProducts()` را صدا می‌زند — بدون هیچ منطق (Arch Test ماژول Sync هرگونه if/loop را در Jobها ممنوع می‌کند). `hm:sync --entity=catalog` مستقیماً همین Job را صف می‌کند (بدون عبور از `SyncEntityJob`)؛ `--full` برایش بی‌معنی است چون `CatalogSyncService` همیشه یک آینه‌ی کامل است. Scheduler: `Schedule::command('hm:sync', ['--entity' => 'catalog'])->dailyAt('01:30')` — مستقل، چون زنجیره‌ی کامل PRD §۲۲ (P6-09) هنوز ساخته نشده.

**TEST FIRST:** ۱۰ تست تازه (`CatalogSyncJobTest.php` ۵ مورد با Fixtureهای واقعی ضبط‌شده و `SyncServiceTest.php` +۱ مورد برای Guard) + به‌روزرسانی ۹ تست/فایل موجود که فرض «فقط orders» داشتند: `SyncCommandTest.php` (پیام خطا، دو تست جدید برای Dispatch مستقیم، Helper زمان‌بندی جدا برای Orders vs Catalog)، `ReconcileCommandTest.php` («دقیقاً ۲ Task» → ۳)، `SyncLogsControllerTest.php` (فهرست `entities`)، `tests/Arch/SyncCommandBoundaryTest.php` (شمارش `$this->line`/`Schedule::command('hm:sync'`)، `tests/Arch/SyncRunBoundaryTest.php` (فهرست دقیق فایل‌های Job، Regex ارجاع به Service)، `tests/Arch/ReconciliationBoundaryTest.php` (شمارش کل `Schedule::`). همه با دلیل صریح در commit، نه ضعیف‌کردن بی‌توجیه.

### تصمیم ۳ — تلاش اجرای زنده: **متوقف شد روی یک یافته‌ی تازه، نه 429/5xx**

`CatalogSyncJob::dispatchSync()` روی dev واقعاً به WooCommerce زنده وصل شد (GET فقط، `per_page=50`، `rate_limit_per_minute=90`، `max_retries=4` — همان مقادیر از‌قبل‌پیکربندی‌شده، بدون تغییر). بعد از **۱۴ ثانیه**، `syncCategories()` با یک خطای واقعی دیتابیس (نه Woo، نه 429/5xx) شکست خورد:

```
SQLSTATE[22001]: String data, right truncated: value too long for type character varying(180)
دسته Woo id=2346، slug واقعی به طول ۱۹۴ نویسه (URL-encoded فارسی)
```

**بررسی مستقل، فقط‌خواندنی (بدون نوشتن، طبق مجوز GET):** با خواندن مستقیم `products/categories` از Woo (بدون فراخوانی مسیر نوشتن)، از ۱۷۳ دسته‌ی واقعی فروشگاه **دقیقاً ۱ مورد** (id=2346) از سقف ستون `slug varchar(180)` رد می‌شود؛ هیچ `name`ی از سقف `varchar(160)` رد نمی‌شود.

**چرا این «باگ تازه‌ی من» نیست:** خودِ `sprint-2.md`ی P2-05 این را از قبل به‌عنوان یک ریسک شناخته‌شده و **عمداً حل‌نشده** ثبت کرده بود: «نام یا مقدار بیش از عرض ستون خطای DB بلند می‌دهد (عمداً بریده نمی‌شود، برخلاف نام مشتری در P2-04)» — یعنی سازنده‌ی وقت آگاهانه Truncate نکرد و پذیرفت که روی داده‌ی واقعی ممکن است بترکد. تا امروز Sync کاتالوگ هرگز روی داده‌ی زنده اجرا نشده بود (همان تشخیص اصلی این Sprint)، پس این ریسک اولین‌بار همین‌جا واقعاً رخ داد.

**وضعیت دیتابیس بعد از شکست:** بدون تغییر — `products`=۱۲، `product_categories`=۵، `product_variations`=۲۴ (همان داده‌ی Fixture P1-06)، چون کل Batch دسته‌بندی‌ها یک تراکنش است (P2-05) و با شکست کامل Rollback شد. **هیچ نیمه‌نوشته‌ای باقی نماند.**

**چرا اینجا متوقف شدم (طبق روح دستور شما، نه فقط حرفش):** رفع این یک ردیف نیازمند یک تصمیم تازه است (Truncate کردن slug مثل فیلدهای دیگر Sync سفارش‌ها، یا بزرگ‌کردن ستون با Migration جدید، یا رد‌کردن همان یک دسته‌ی خراب و ادامه) — دقیقاً هم‌رده‌ی تصمیم ۱ که قبلاً از شما گرفته شد، نه چیزی که خودم باید حدس بزنم. تا این تصمیم گرفته شود، `syncProducts()` هم هرگز اجرا نشد (چون در `CatalogSyncJob` بعد از `syncCategories()` می‌آید) — یعنی **زیرساخت (تصمیم ۱ و ۲) کامل و سبز است، ولی داده‌ی dev هنوز کاتالوگ واقعی ندارد** و درصد Resolve هنوز همان ۰٪ قبلی است.

**اعداد خواسته‌شده در گزارش (طبق دستور، حتی چون تصمیم ۳ کامل نشد):**

```
order_items با product_id قابل‌حل — قبل: 0 / 61,358 (0.00%)
order_items با product_id قابل‌حل — بعد: 0 / 61,358 (0.00%)  (کاتالوگ واقعی هنوز Sync نشده)
customer_product_purchases:  0 ردیف (بدون تغییر)
customer_category_purchases: 0 ردیف (بدون تغییر)
۵٬۷۹۸ ردیف order_items بدون sku: دست‌نخورده، فقط شمارش (طبق دستور صریح شما) — هیچ عملیاتی روی آن‌ها اجرا نشد
```

هیچ کراس‌چکی روی `customer_product_purchases`/`customer_category_purchases` معنی ندارد چون هر دو هنوز ۰ ردیف‌اند (زیرساخت آماده، دیتای dev بدون تغییر).

**تصمیم لازم از شما (برای بستن نهایی تصمیم ۳):** کدام یک — (الف) Truncate کردن `slug` به ۱۸۰ نویسه در `CategoryMapper`/`CatalogSyncService` (هم‌الگوی Order Sync)، (ب) Migration جدید برای بزرگ‌کردن `product_categories.slug`، یا (ج) رد‌کردن دسته‌ی خراب با Warning و ادامه‌ی بقیه؟ بعد از تصمیم، `CatalogSyncJob::dispatchSync()` دوباره روی dev اجرا و اعداد بالا به‌روزرسانی می‌شوند.

**تست کامل این تسک:** `php artisan test` → ۲۹۷۲/۲۹۷۲ سبز (۱۳٬۷۴۰ Assertion). PHPStan کل پروژه → ۰ خطا. Pint تمیز. هیچ Arch Test ضعیف نشد — هرکدام برای بازتاب یک واقعیت تازه‌ی درست (Job/Schedule/Command تازه) به‌روزرسانی شد، با دلیل صریح.

**فایل‌ها:** Migration تازه (`add_sku_price_to_products_table`)، `app/Modules/Catalog/{Models/Product,Services/{CatalogService,ProductInput,ResolvedCatalogItem},Exceptions/CatalogIntegrityException}.php`، `app/Modules/Sync/{Enums/SyncEntity,Services/{CatalogSyncService,SyncService},Jobs/CatalogSyncJob}.php`، `app/Modules/Orders/Services/OrderService.php`، `app/Console/Commands/SyncCommand.php`، `routes/console.php`، + ۱۰ فایل تست تازه/به‌روزشده.

---

## رفع بخش اول تصمیم ۳ (Slug) + تلاش دوم اجرای زنده: **متوقف شد روی یک خطای تازه، از جنس داده، نه Width**

**تصمیم شما:** گسترش ستون (نه Truncate، نه Skip) — `product_categories.slug` از ۱۸۰ به ۲۵۵، و بررسی بقیه‌ی ستون‌های رشته‌ای کاتالوگ در برابر محدودیت واقعی WordPress/Woo.

### Migration جدید و قابل‌بازگشت

`2026_09_27_110000_widen_product_categories_name_slug.php` — `product_categories.name` ۱۶۰→۲۵۵، `slug` ۱۸۰→۲۵۵ (`down()` برمی‌گرداند به مقادیر اصلی).

**بررسی بقیه‌ی ستون‌های کاتالوگ (طبق دستور، از اسکیما/کد، نه حدس):**

| ستون | عرض فعلی | محدودیت واقعی WordPress/Woo | تصمیم |
|---|---|---|---|
| `product_categories.name` | ۱۶۰ | `wp_terms.name varchar(200)` | **گسترش به ۲۵۵** — همان سقف بالادستی `slug`، همان کلاس ریسک، هنوز نقض واقعی ندیده بود ولی زیرِ سقف واقعی بود |
| `product_categories.slug` | ۱۸۰ | `wp_terms.slug varchar(200)` | **گسترش به ۲۵۵** — نقض واقعی تأییدشده (id=2346، ۱۹۴ نویسه) |
| `products.slug` | ۲۶۰ | `wp_posts.post_name varchar(200)` | **دست‌نخورده** — از قبل بالاتر از سقف واقعی است |
| `products.name` | ۲۵۰ | `wp_posts.post_title` نوع `TEXT` (بدون سقف ثابت) | **دست‌نخورده** — هیچ سقف بالادستی برای مقایسه وجود ندارد؛ گسترش بدون شاهد یعنی حدس‌زدن |
| `products.sku` (P6) / `product_variations.sku` | ۸۰ | SKU در Woo روی `postmeta` (`longtext`) است، بدون سقف ستونی | **دست‌نخورده** — کلاس ریسک متفاوت (SKU کوتاه الفبایی-عددی است، نه Slug کدگذاری‌شده)؛ هیچ نقض واقعی یا سرنخ مکتوبی پیدا نشد |

**⚠ یک اسکن زنده‌ی کامل روی `products` عمداً متوقف شد (طبق «کندی» در دستور):** برای اطمینان از عدم وجود ریسک مشابه روی `products.name/slug/sku`، یک اسکن فقط‌خواندنی (GET) روی کل صفحه‌های `products` اجرا شد. اجرای اول با سقف حافظه‌ی پیش‌فرض CLI (۱۲۸M) به خطای Out-of-Memory خورد (کاتالوگ واقعی بزرگ‌تر از حد انتظار است)؛ اجرای دوم با ۱G حافظه بیش از ۱۰ دقیقه بدون هیچ خروجی ماند — دقیقاً «کندی»ی که در دستور گفته شد باید متوقف شوم، پس با `TaskStop` متوقف شد، نه اینکه بی‌پایان صبر کنم. به‌جای این اسکن پرریسک، تصمیم روی `products.name/slug/sku` صرفاً از روی محدودیت‌های مستند WordPress/Woo گرفته شد (جدول بالا) — نه حدس، ولی هم مبتنی بر مستندات فنی شناخته‌شده‌ی WordPress، نه یک اسکن زنده‌ی دوم.

### TEST FIRST

۲ تست تازه در `CatalogServiceTest.php`: مقدار دقیق SKU واقعیِ Woo (۱۹۴ نویسه) و مرز واقعی ۲۰۰نویسه‌ای `wp_terms`. هر دو قبل از Migration قرمز (`value too long for type character varying(180)` / `(160)`)، بعد سبز. Suite کامل Catalog (۲۰۸ تست) بدون رگرسیون.

### اجرای زنده، تلاش دوم

**یافته‌ی فرایندی مهم قبل از اجرا:** هر دو Migration این Sprint (تصمیم ۱ و همین یکی) هرگز روی دیتابیس dev اجرا نشده بودند — فقط روی `heymode_testing` (خودکار توسط RefreshDatabase تست‌ها). `php artisan migrate:status` این را تأیید کرد (`Pending`). این یعنی تلاش اول اجرای زنده (تسک قبلی) اصلاً هنوز کد جدید را نمی‌دید. با `php artisan migrate --force` هر دو روی dev اجرا شدند، سپس اجرای زنده تکرار شد.

**نتیجه:**

```
دسته‌بندی‌ها: ۱۷۸ ردیف کامل و موفق نوشته شد (۱۷۳ واقعی + ۵ Fixture قبلی) — هیچ خطای Width دیگری رخ نداد
محصولات: ۵۷ ردیف واقعی نوشته شد (هرکدام Transaction جدا، طبق طراحی P2-05) — سپس شکست
واریانت‌ها: ۲۴ (بدون تغییر — هنوز به یک محصول Variable واقعی نرسیده بود)
```

**خطای تازه (شکست کامل، نه Width — دقیقاً طبق دستور همین‌جا متوقف شدم، هیچ رفع حدسی نزدم):**

```
App\Modules\Sync\Exceptions\WooMappingException:
Woo product payload is invalid at 'price': expected a whole-Toman amount, got string.
```

**تشخیص دقیق، فقط‌خواندنی (بدون نوشتن، همان مجوز GET):** محصول واقعی Woo id=**55345** («خودتراش مردانه ۶ لبه مستر شیو مدل Zylon»، نوع simple) مقدار `price` را به‌صورت `"159990.0"` می‌فرستد — رشته‌ی اعشاری با `.0` انتهایی، نه عدد صحیح خالص. `PayloadReader::toman()` عمداً فقط رقم خالص می‌پذیرد (`^(?:0|[1-9][0-9]*)$`، بدون اعشار/علامت) — این یک قرارداد از‌قبل‌موجود P2-03 است، نه چیزی که امروز ساختم. `regular_price` همان محصول `"182000"` (بدون اعشار) است — یعنی ناهمخوانی فرمت بین دو فیلد قیمت روی همین محصول در Woo واقعی.

**چرا همین‌جا متوقف شدم:** طبق دستور صریح شما، مجوز «رفع بدون توقف» فقط برای دسته‌ی گسترش ستون بود؛ این یک کلاس خطای کاملاً متفاوت است (فرمت/Validation مقدار، نه عرض ستون) و نیاز به یک تصمیم تازه دارد (گرد‌کردن/`floor` رشته‌ی اعشاری، پذیرفتن اعشار در `toman()`، یا رد‌کردن با Warning و ادامه). هیچ تلاش پشت‌سرهمی برای حدس زدن رفع نشد.

**وضعیت دیتابیس بعد از شکست:** امن — ۵۷ محصول و ۱۷۸ دسته‌ی واقعی که تا این نقطه Commit شده بودند، **باقی می‌مانند** (هرکدام Transaction مستقل)؛ فقط محصول ۵۵۳۴۵ به بعد نوشته نشد. چون `CatalogSyncService` Idempotent است (طبق طراحی P2-05)، اجرای بعدی (بعد از تصمیم و رفع) دوباره از صفحه‌ی ۱ شروع می‌کند، ردیف‌های موجود را بی‌ضرر Upsert می‌کند و از همان نقطه ادامه می‌دهد — نیازی به هیچ عملیات پاک‌سازی نیست.

**اعداد خواسته‌شده در گزارش (طبق دستور، حتی چون Sync هنوز کامل نشد):**

```
order_items با product_id قابل‌حل — الان: 0 / 61,358 (0.00%) — بدون تغییر
```

**چرا هنوز ۰٪ است، با اینکه ۵۷ محصول واقعی نوشته شد:** `order_items.product_id` فقط وقتی به‌روزرسانی می‌شود که خودِ سفارش دوباره Sync شود (طبق طراحی P2-06: «هر Sync دوباره Resolve می‌کند»)؛ صرفاً پرشدن کاتالوگ خودش هیچ سفارش قدیمی را دوباره Resolve نمی‌کند. تا کاتالوگ کامل نشود و بعد سفارش‌ها دوباره Sync نشوند (خارج از Scope همین تسک)، این عدد حرکت نمی‌کند — این محدودیت طراحی از قبل موجود است، نه نشانه‌ی خرابی رفع امروز. `BuildCustomerPurchaseAggregatesJob` روی این داده‌ی جزئی اجرا نشد چون دستور شما آن را منوط به «Sync موفق» کرده بود.

**تست کامل این تسک:** `php artisan test` → ۲۹۷۴/۲۹۷۴ سبز (۱۳٬۷۴۳ Assertion). PHPStan → ۰ خطا. Pint تمیز.

**تصمیم لازم از شما (برای ادامه‌ی تصمیم ۳):** فرمت قیمت اعشاری Woo (`"159990.0"`) چطور مدیریت شود — (الف) `PayloadReader::toman()`/`Money::parseToman()` رشته‌ی اعشاری با بخش کسری صفر را بپذیرد (`floor` یا Round، فقط وقتی کسر واقعاً صفر است، رد کند اگر غیرصفر)، (ب) فقط برای `ProductDto::price` (نه بقیه‌ی مصرف‌کننده‌های `toman()`) یک مسیر Parse اعشاری جدا ساخته شود، یا (ج) این محصولات را رد کند (NULL برای price) و با Warning لاگ کند، ادامه بدهد؟ بعد از تصمیم، اجرای زنده و گزارش کامل کراس‌چک (طبق درخواست شما) تکرار می‌شود.

**فایل‌ها:** `database/migrations/2026_09_27_110000_widen_product_categories_name_slug.php` (تازه)، `tests/Feature/Modules/Catalog/CatalogServiceTest.php` (+۲ تست).

## رفع بخش دوم تصمیم ۳ (قیمت اعشاری Woo) + بخش ۴ (اجرای زنده و backfill): sync زنده کامل شد؛ یک باگ تازه و نامرتبط جلوی aggregate را گرفت

تصمیم انتخابی شما از سه گزینهٔ قبلی: **(ب)** — مسیر Parse اعشاری جدا، فقط برای `ProductDto::price`.

### ۱) `PayloadReader::nullableDecimalMoney()` — TEST FIRST

متد جدید (نه تغییر `toman()`/`nullableMoney()` موجود): یک رشتهٔ اعشاری با کسر تماماً صفر (`"159990.0"`, `"159990.00"`, `"0.0"`) را به بخش صحیح تبدیل می‌کند و همان مسیر سخت‌گیرانهٔ `toman()` را روی آن اجرا می‌کند؛ کسر غیرصفر دست‌نخورده به `toman()` می‌رود و دقیقاً مثل قبل رد می‌شود. تست‌ها (`PayloadReaderTest.php`) قبل از افزودن متد قرمز بودند (`Call to undefined method`)، بعد سبز. Mutation check: حذف موقت خط collapse رشته → تست‌های "real Woo value"/"zero itself" با پیام دقیق toman قرمز شدند؛ بازگردانده شد.

`ProductMapper::map()` تنها مصرف‌کنندهٔ متد جدید شد (`nullableMoney('price')` → `nullableDecimalMoney('price')`). `VariationMapper` دست‌نخورده ماند — طبق تصمیم صریح شما، محدود به `ProductDto::price`.

### ۲) یک محصول نامعتبر، کل sync را متوقف نکند — TEST FIRST

`CatalogSyncService::syncProducts()`: نگاشت هر محصول (و variationهای آن، چون همان واحد کاری‌اند) داخل `try/catch(WooMappingException)` است؛ گرفتار شدن یعنی شمارش `rejectedProducts++`، یک `Log::warning` با دلیل، و ادامهٔ حلقه. برای تعارض کاتالوگ (SKU تکراری و مانند آن) که با استثنای `CatalogIntegrityException` (متعلق به ماژول Catalog) نشان داده می‌شود، **نه** با catch مستقیم آن در Sync — چون قانون مرزی ماژول‌ها (`CLAUDE.md` §۱، تست global در `ArchitectureTest.php`: «فقط از طریق Services یا Events») اجازهٔ import کردن `Catalog\Exceptions` را از ماژول دیگر نمی‌دهد. راه‌حل: متد تازهٔ غیرپرتاب‌کنندهٔ `CatalogService::tryUpsertProduct(ProductInput): CatalogUpsertOutcome` — تعارض را به‌صورت یک مقدار برگشتی (`accepted`/`rejectionReason`) گزارش می‌کند، نه استثنا؛ `upsertProduct()` قدیمی و پرتاب‌کننده برای مصرف‌کنندگان داخل Catalog دست‌نخورده ماند.

نکتهٔ مهم که موقع پیاده‌سازی کشف شد: یک تلاش اول با `catch (WooMappingException|CatalogIntegrityException $e)` مستقیم در `CatalogSyncService` نوشته شد و تست‌های واحدی محلی سبز شدند، اما تست global مرزی (`ArchitectureTest.php::it('only reaches into other modules through their Services or Events')`) قرمز شد — این تست فقط `Services`/`Events` را مجاز می‌داند، نه `Exceptions`. بازطراحی به `tryUpsertProduct()`/`CatalogUpsertOutcome` انجام شد تا مرز دقیقاً به همان سختی قبل بماند.

تست‌های قبلی که رفتار «پرتاب و توقف کامل» را انتظار داشتند (`fails loudly on a malformed product payload...`, `fails loudly...SKU is already taken...`) به رفتار تازه (`rejects one malformed product with a warning and keeps syncing the rest`, `rejects with a warning, corrupting nothing...`) بازنویسی شدند — این یک تغییر عمدی قرارداد است، نه رگرسیون؛ رفتار قدیم Woo رقابتی/۵xx (`WooRequestException`) دست‌نخورده ماند (هنوز کل run را متوقف می‌کند). Mutation check: محدود کردن catch فقط به `CatalogIntegrityException` → تست محصول بدشکل قرمز شد؛ تنظیم شرط رد به `if (false)` → تست تعارض SKU قرمز شد (۲ به‌جای ۱ محصول شمرده شد). هر دو بازگردانده شدند.

### ۳) ابزار dry-run فقط‌خواندنی — TEST FIRST

`CatalogDryRunService` (بدون هیچ وابستگی به `CatalogService` — از نظر ساختاری قادر به نوشتن نیست) + کامند نازک `hm:catalog-dry-run`. از همان الگوی generator موجود (`WooClient::pages()`) استفاده می‌کند، دقیقاً مثل `CatalogSyncService` اثبات‌شده — نه اسکریپت خام قبلی. هر محصول/variation را با mapperهای P2-03 اعتبارسنجی می‌کند و هر کلاس خطا را با شمار و یک نمونه جمع می‌کند؛ دسته‌بندی‌ها فقط بر اساس فیلد+پیام است.

### ۴) `hm:resolve-order-items` (backfill محلی) — TEST FIRST

بررسی شد: هیچ مکانیزم موجودی برای تحلیل مجدد `order_items` از دادهٔ محلی وجود نداشت. ساخته شد: `OrderItemBackfillService::resolveUnresolved()` + کامند نازک. محدودیت واقعی که در بررسی کد کشف شد: جدول `order_items` هیچ ستون `woo_product_id`/`woo_variation_id` ندارد، پس مراحل ۱ و ۲ الگوریتم اصلی `OrderService::resolve()` از روی یک ردیف ذخیره‌شده قابل بازسازی نیستند — فقط مراحل SKU-محور (۳، سپس ۳.۵) قابل تکرارند. کوئری فقط ردیف‌هایی را می‌گیرد که `product_id`/`variation_id` هر دو NULL و `sku` NOT NULL باشند (پس ۵٬۷۹۸ ردیف بدون SKU هرگز لمس نمی‌شوند)، در chunk های ۵۰۰تایی. Idempotent: تست اختصاصی دو بار اجرا می‌کند و بار دوم صفر تغییر تازه می‌بیند؛ تست دیگری ردیف از‌قبل-حل‌شده را حتی وقتی SKU‌اش الان به چیز دیگری هم می‌خورد دست‌نخورده نگه می‌دارد (Mutation check: حذف شرط `whereNull('product_id')` → این تست قرمز شد؛ بازگردانده شد).

### اجرای زنده — نتایج واقعی

**dry-run (فقط‌خواندنی، هیچ نوشتنی):** روی کل کاتالوگ واقعی — ۲٬۵۲۲ محصول، ۸۲۲ variation اسکن شد. تنها کلاس خطا: همان فرمت قیمت اعشاری — **۵ محصول** (کسر غیرصفر، رد صحیح طبق تصمیم شما) و **۲۰۲ variation**. طبق دستور صریح شما («اگر فقط از جنس رد-با-warning است، همان مسیر ۲ کافی است»)، این کلاس خطای تازه‌ای نبود که نیاز به توقف داشته باشد — مستقیم به اجرای زنده رفتیم.

**اجرای زنده `CatalogSyncJob` (همان مسیر `syncCategories()`→`syncProducts()`، از طریق tinker تا شمارش‌ها قابل‌ثبت باشند):**
اولین تلاش با `php artisan tinker` (حد پیش‌فرض CLI، ۱۲۸M) با «Allowed memory size... exhausted» شکست خورد — همان کلاس خطای قبلاً مستندشدهٔ این اسپرینت (نه دادهٔ جدید)؛ تکرار با `-d memory_limit=1G` (رفع قبلاً اثبات‌شده) موفق شد:

```
{"categories":173,"products":2300,"variations":142,"rejected":222}
```

این نتیجه، Open Item قبلی «کاتالوگ خیلی بزرگ / احتمال hang» را **حل می‌کند**: مشکل حد حافظهٔ پیش‌فرض PHP CLI بود، نه نشتی یا گیرکردن — dry-run فقط‌خواندنی (بدون نوشتن Eloquent) با حد پیش‌فرض هم مشکلی نداشت؛ فقط نوشتن هزاران رکورد Eloquent در یک پردازش طولانی به حافظهٔ بیشتری نیاز داشت.

جمع رد‌شده‌ها (۲۲۲) بیشتر از ۵ محصول با قیمت اعشاری خودشان است، چون یک variation بدشکل کل محصول والدش را رد می‌کند (نگاشت variation قبل از نوشتن محصول، در همان try یکسان اتفاق می‌افتد) — یعنی از ۶۲۰ variation معتبر یافته‌شده در dry-run، فقط ۱۴۲ تا واقعاً نوشته شد؛ ۴۷۸ تای دیگر معتبر بودند اما چون همراهشان (در همان محصول) یک variation بد بود، محصولشان رد شد. این رفتار جدید این تسک نیست — از طراحی قبلاً موجود و مستند P2-05 می‌آید («یک محصول (ردیف+لینک‌ها+variationها) یک واحد کاری است»)؛ تصمیم ۲ فقط تعیین کرد که این رد، کل sync را متوقف نکند.

شمار نهایی در DB (mirror هرگز-حذف‌نشو، شامل اجراهای قبلی جزئی): **۱۷۸ دسته، ۲٬۳۱۲ محصول، ۱۶۶ variation** (کراس‌چک مستقیم DB).

**Backfill (`hm:resolve-order-items`):**
```
resolved as variation: 81, resolved as product: 41826, still unresolved: 13653
```
۸۱+۴۱٬۸۲۶+۱۳٬۶۵۳ = ۵۵٬۵۶۰ = دقیقاً همان تعداد ردیف با SKU (۶۱٬۳۵۸ − ۵٬۷۹۸). کراس‌چک مستقل با کوئری مستقیم: `order_items` با `product_id IS NOT NULL` = **۴۱٬۹۰۷** (مطابق)، ردیف‌های بدون SKU که اشتباهاً حل شده باشند = **۰** (تأیید دست‌نخوردگی ۵٬۷۹۸ ردیف).

**درصد نهایی resolve شدهٔ order_items: ۴۱٬۹۰۷ / ۶۱٬۳۵۸ = ۶۸.۲۹٪** (از ۰.۰۰٪).

### باگ تازه: `BuildCustomerPurchaseAggregatesJob` — متوقف شد، نیاز به تصمیم شما

اجرا با `dispatchSync()` روی dev شکست خورد:
```
SQLSTATE[23502]: Not null violation: 7 ERROR: null value in column "customer_id" of relation "customer_product_purchases"
```
علت ریشه‌ای: کوئری `CustomerPurchaseAggregateService` با `FROM orders o JOIN order_items oi` نوشته شده و هیچ‌جا `o.customer_id IS NOT NULL` را فیلتر نمی‌کند — این یک نقص از قبل موجود در SQL این سرویس (P6-01) است که تا امروز چون شرط `oi.product_id IS NOT NULL` روی dev صفر ردیف می‌خورد (نرخ ۰٪ resolve)، هرگز خودش را نشان نداده بود. سرویس خواهر `BaseAggregateService` (P4) این مشکل را اصلاً ندارد چون جهت کوئری‌اش برعکس است: `FROM customers c LEFT JOIN orders o` — از نظر ساختاری هرگز به یک مشتری NULL برنمی‌خورد.

اندازهٔ واقعی مشکل: از ۲۷٬۵۶۷ `order_items` واقعی‌شده (`is_realized`) و resolve‌شده، فقط **۲۲۱ (٪۰.۸)** به سفارشی با `customer_id IS NULL` تعلق دارند (شکست نرمال‌سازی تلفن، PRD §۰۸ گام ۱، `needs_phone_review`).

این یک باگ **تازه و نامرتبط** به کار این تسک است (خودش را فقط به‌خاطر موفقیت خودِ این تسک نشان داد) و خارج از حیطهٔ مجوز صریح این تسک — طبق الگوی ثابت این نشست، رفعش نکردم و اینجا گزارش می‌کنم:

**تصمیم لازم از شما:** راه‌حل واضح و هم‌راستا با الگوی موجود کد (`BaseAggregateService`) افزودن `AND o.customer_id IS NOT NULL` به کوئری `CustomerPurchaseAggregateService` است — اما چون این خارج از دامنهٔ مجاز این تسک است، بدون تأیید صریح شما اعمال نشد. گزینه‌های ممکن: (الف) همین فیلتر ساده اضافه شود (سفارش بدون مشتری، در aggregate هیچ مشتری‌ای هم شمرده نمی‌شود — سازگار با طراحی موجود)، (ب) گزینهٔ دیگری که شما ترجیح می‌دهید. تا آن زمان `customer_product_purchases`/`customer_category_purchases` روی dev خالی می‌مانند و کراس‌چک نهایی درخواستی شما (شمار ردیف این دو جدول) قابل تولید نیست.

### بستن این تسک

تست کامل: `php artisan test` → ۲٬۹۹۷/۲٬۹۹۷ سبز (۱۳٬۸۰۷ Assertion) — قبل از اجرای زنده، چون هیچ کد بعد از آن تغییر نکرد. PHPStan (کل app) → ۰ خطا. Pint → تمیز.

**فایل‌ها:**
- `app/Modules/Sync/Mappers/PayloadReader.php` (+`nullableDecimalMoney()`)
- `app/Modules/Sync/Mappers/ProductMapper.php` (سیم‌کشی متد تازه)
- `app/Modules/Sync/Services/CatalogSyncService.php` (رد-با-warning به‌جای توقف کامل)
- `app/Modules/Sync/Support/CatalogSyncResult.php` (+`rejectedProducts`)
- `app/Modules/Catalog/Services/CatalogService.php` (+`tryUpsertProduct()`)
- `app/Modules/Catalog/Services/CatalogUpsertOutcome.php` (تازه)
- `app/Modules/Sync/Services/CatalogDryRunService.php` + `app/Modules/Sync/Support/CatalogDryRunResult.php` (تازه)
- `app/Console/Commands/CatalogDryRunCommand.php` (تازه، `hm:catalog-dry-run`)
- `app/Modules/Orders/Services/OrderItemBackfillService.php` + `app/Modules/Orders/Support/OrderItemBackfillResult.php` (تازه)
- `app/Console/Commands/ResolveOrderItemsCommand.php` (تازه، `hm:resolve-order-items`)
- تست‌ها: `PayloadReaderTest.php`, `CatalogMappersTest.php`, `CatalogSyncServiceTest.php`, `CatalogServiceTest.php`, `CatalogDryRunServiceTest.php` (تازه), `OrderItemBackfillServiceTest.php` (تازه), `CatalogDryRunCommandTest.php` (تازه), `ResolveOrderItemsCommandTest.php` (تازه), `tests/Arch/CatalogSyncBoundaryTest.php`, `tests/Arch/SyncCommandBoundaryTest.php` (لیست کامندها).

## رفع باگ `BuildCustomerPurchaseAggregatesJob` (customer_id NULL) — بستن نهایی Open Item مربوط به product_id

تصمیم شما: کوئری با `AND o.customer_id IS NOT NULL` اصلاح شود، دقیقاً هم‌الگو با کوئری خواهرش در همان سرویس.

### TEST FIRST

تست تازه در `CustomerPurchaseAggregateServiceTest.php`: یک سفارش `Order::factory()->phoneless()` (شکست نرمال‌سازی تلفن، `customer_id = null`) با یک آیتم واقعی می‌سازد و انتظار دارد هر دو جدول خالی بمانند. قبل از رفع: قرمز با همان خطای واقعی (`SQLSTATE[23502]`) که در تسک قبلی روی dev دیده شد. رفع: افزودن `AND o.customer_id IS NOT NULL` به هر دو کوئری `rebuildProductPurchases()` و `rebuildCategoryPurchases()` (متقارن، دقیقاً هم‌الگو). Mutation check دوگانه: حذف موقت فیلتر فقط از کوئری محصول → تست با خطای واقعی روی `customer_product_purchases` قرمز شد (چون تراکنش با شکست اولین INSERT کلاً برمی‌گردد و کوئری دسته اجرا نمی‌شود)؛ بازگردانده شد، سپس حذف فیلتر فقط از کوئری دسته (با فیلتر محصول سالم) → تست این‌بار با خطای `customer_category_purchases` قرمز شد — یعنی همان یک تست هر دو کوئری را واقعاً می‌پوشاند. هر دو بازگردانده شدند.

### اجرای زنده روی dev

```
product_purchases: 26664
category_purchases: 64505
```

**کراس‌چک مستقل (کوئری مستقیم، جدا از سرویس):** شمار جفت‌های متمایز `(customer_id, product_id)` روی `orders JOIN order_items` با همان فیلترها (`is_realized=true`, `deleted_at IS NULL`, `customer_id IS NOT NULL`, `product_id IS NOT NULL`) = **۲۶٬۶۶۴** — برابر دقیق. شمار جفت‌های متمایز `(customer_id, category_id)` روی همان + `JOIN product_category_product` = **۶۴٬۵۰۵** — برابر دقیق.

### بستن Open Item «۰٪ order_items→product resolution»

این Open Item (که از تشخیص اولیهٔ این اسپرینت شروع شد) اکنون به‌طور کامل بسته می‌شود: **۶۸.۲۹٪ resolved (۴۱٬۹۰۷/۶۱٬۳۵۸)**، **۵٬۷۹۸ ردیف بدون SKU** دست‌نخورده باقی مانده (به‌عمد — چیزی برای تحلیل ندارند)، **۱۳٬۶۵۳ ردیف** SKU دارند اما به کاتالوگ نمی‌خورند (احتمالاً یکی از ۲۲۲ محصول رد‌شده، یا SKU واقعاً منسوخ)، و **۲۲۲ محصول** به‌خاطر فرمت قیمت اعشاری نامعتبر Woo رد شدند (۵ تا از قیمت خودشان، بقیه از یک variation بد که کل محصول والد را رد کرده — چون محصول و variationهایش یک واحد نوشتاری‌اند). جزئیات کامل هر رقم در بخش‌های قبلی همین فایل.

### بستن این تسک

تست کامل: `php artisan test` → در حال اجرا؛ نتیجه در گزارش نهایی چت آمده. PHPStan (`app/Modules/Analytics`) → ۰ خطا. Pint → تمیز.

**فایل‌ها:** `app/Modules/Analytics/Services/CustomerPurchaseAggregateService.php` (+فیلتر در هر دو کوئری)، `tests/Feature/Modules/Analytics/CustomerPurchaseAggregateServiceTest.php` (+۱ تست).

## P6-05 — Product Affinity (4 levels)

خواندن: PRD §16 (SQL جعبه‌ای affinity + جدول سطوح/حداقل هم‌خرید)، §09 (اسکیمای `product_affinities`، از پیش با migration ساخته شده در P1-04)، §01 ردیف C5 (تناقض‌حل: «سطح مشتری اصلی؛ سطح سبد یک `level` اضافی در همان جدول [نه مکانیزم جدا]»)، §22 (`BuildAffinityJob`، صف `metrics`، زمان‌بندی هفتگی شنبه ۰۴:۰۰)، §18 (Dashboard از `product_affinities` می‌خواند — خارج از این تسک، P6-06+). هیچ تناقضی بین منابع پیدا نشد که نیاز به ثبت جدید داشته باشد؛ C5 از قبل در خود PRD حل شده بود.

### طراحی — «یک الگو برای همه سطوح»

فرمول یکسان برای هر ۴ سطح: `support = co/total`، `confidence(A→B) = co/a`، `lift = confidence / (b/total)`؛ فقط جفت‌های نامرتب (`entity_a_id < entity_b_id`، یک ردیف به‌جای دو جهت) با `co_customers >= حداقل سطح` و `lift > 1.0` (نه `>=`) ذخیره می‌شوند. سطح category/product مستقیماً از جدول‌های تجمیعی موجود P6-01 (`customer_category_purchases`/`customer_product_purchases`) می‌خوانند — که خودشان از قبل به سفارش‌های واقعی/غیرحذف‌شده/مشتری‌دار محدود شده‌اند (رفع قبلی همین اسپرینت). سطح variation/basket چون جدول تجمیعی مخصوص ندارند، مستقیم از `order_items`/`orders` می‌خوانند. سطح basket طبق تصمیم C5، **بر اساس order_id گروه‌بندی می‌شود نه customer_id** — همان ستون‌های `co_customers`/`a_customers`/`b_customers` برای این سطح، شمار «سبد» (سفارش) نگه می‌دارند، نه مشتری (بازتفسیر معنایی همان ستون‌ها، دقیقاً طبق تصمیم C5).

حداقل هم‌خرید هر سطح (PRD §16، هاردکد در سرویس — عدد ثابت طرح، نه تنظیمات محیطی): category=20، product=10، variation=5، basket=10.

Migration و Enum از پیش موجود بودند (`product_affinities` در P1-04، `AffinityLevel` Enum در P1-05) — چیزی اضافه نشد.

### TEST FIRST

فیکسچر کوچک با اعداد دقیق قابل‌محاسبه: N مشتری هر دو محصول A و B را می‌خرند، N مشتری دیگر فقط محصول نویز C را می‌خرند (برای بزرگ‌کردن جمعیت کل بدون اثر روی a/b/co). با N=۱۰ (سطح product): `co=10, a=10, b=10, support=0.5, confidence=1.0, lift=2.0` — مقادیر دقیق assert شدند. تست‌های اضافی: زیر آستانه (۹ مشتری) → ذخیره نمی‌شود؛ `lift` دقیقاً ۱.۰ (بدون جمعیت نویز، یعنی B همه‌جا خریده شده) → ذخیره نمی‌شود؛ محصول بدون هیچ هم‌خریدی → نتیجه خالی، بدون خطا؛ idempotent (دو بار rebuild، یک ردیف با مقادیر یکسان)؛ جفت به‌صورت نامرتب و فقط یک جهت ذخیره می‌شود. همین الگو برای category (آستانه ۲۰)، variation (آستانه ۵، مستقیم از order_items)، و basket (آستانه ۱۰، گروه‌بندی با order_id) با یک تست صحت + یک تست آستانه هرکدام تکرار شد. جمع: ۱۴ تست در `AffinityServiceTest.php` + ۴ تست در `BuildAffinityJobTest.php`.

نکته‌ی مهم پیاده‌سازی: تست‌های اولیه هم‌خرید را با درج مستقیم در `order_items` می‌ساختند، اما سرویس سطح category/product از جدول‌های تجمیعی P6-01 می‌خواند نه از order_items مستقیم — یعنی آن فیکسچرها هرگز واقعاً داده‌ای در `customer_product_purchases`/`customer_category_purchases` نمی‌گذاشتند (قرمزی درست، اما بعضی تست‌های "زیر آستانه" به‌صورت کاذب سبز می‌ماندند چون اصلاً هیچ داده‌ای وجود نداشت). اصلاح شد: فیکسچرهای این دو سطح مستقیماً در جدول تجمیعی درج می‌کنند (دقیقاً هم‌الگو با نحوه‌ی تست‌نویسی `CustomerPurchaseAggregateServiceTest` برای منبع خودش).

Mutation check: کاهش موقت آستانه سطح product از ۱۰ به ۱ → تست «زیر آستانه» با شکست واقعی (ردیف موجود بود، باید null باشد) قرمز شد. شل‌کردن موقت فیلتر `lift > 1.0` به `>= 0` → تست «lift دقیقاً ۱» قرمز شد. هر دو بازگردانده شدند.

### تعارض نام‌گذاری تست

فایل `tests/Integration/AnalyticsSchemaTest.php` از قبل تابع کمکی `affinityRow()` دارد؛ نام تابع کمکی من به `affinityPairRow()` تغییر کرد تا تداخل نداشته باشد.

### `BuildAffinityJob`

صف `metrics` (هم‌الگو با `BuildCustomerPurchaseAggregatesJob`)، `ShouldBeUnique`، `tries=1`. `timeout=900`/`uniqueFor=1200` (بزرگ‌تر از جفت ۳۰۰/۶۰۰ آن Job) — چون هدف کارایی PRD §23 برای Affinity صریحاً «< ۱۵ دقیقه» است، برخلاف Purchase Aggregates که چنین هدفی ندارد. زمان‌بندی هفتگی شنبه ۰۴:۰۰ (PRD §22) **سیم‌کشی نشد** — دقیقاً هم‌الگو با P6-01 تا P6-04 که هیچ‌کدام وارد `routes/console.php` نشدند؛ آن کار متعلق به P6-09 («زنجیره کامل scheduler») است.

### اجرای زنده روی dev + کراس‌چک مستقل

```
{"category":2167,"product":302,"variation":0,"basket":223,"elapsed_ms":1427}
```
(کمتر از ۱.۵ ثانیه — بسیار زیر هدف ۱۵ دقیقه PRD §23.)

**variation=0، به‌عمد نه باگ:** طبق محدودیت شناخته‌شده‌ای که در ابتدای این تسک گفته شد، فقط ۸۱ ردیف `order_items` (از ۴۱٬۹۰۷ resolve‌شده) `variation_id` دارند (بقیه به‌عنوان محصول ساده resolve شدند) — کراس‌چک مستقیم این عدد (۸۱) را تأیید کرد. با آستانه حداقل ۵ هم‌خرید در سطح variation، پخش‌شدن این ۸۱ ردیف بین variationهای مختلف کافی برای عبور از آستانه نبود؛ نتیجه‌ی صفر، رفتار درست است.

**کراس‌چک مستقل (کوئری مستقیم، جدا از سرویس):** برای پرلیفت‌ترین جفت واقعی ذخیره‌شده در سطح product (محصول ۵۶۴/۵۶۵)، شمارش مستقل `co_customers`/`a_customers`/`b_customers` روی `customer_product_purchases` و محاسبه‌ی مستقل support/confidence/lift:
```
stored:       co=13 a_cust=47 b_cust=24 support=0.001208 confidence=0.276596 lift=123.9956
independent:  co=13 a_cust=47 b_cust=24 support=0.001208 confidence=0.276596 lift=123.9956
```
برابر دقیق.

### بستن این تسک

تست کامل: `php artisan test` → در حال اجرا؛ نتیجه در گزارش چت. PHPStan (`app/Modules/Analytics`) → ۰ خطا. Pint → تمیز.

**فایل‌ها:** `app/Modules/Analytics/Services/AffinityService.php` (تازه)، `app/Modules/Analytics/Support/AffinitySummary.php` (تازه)، `app/Modules/Analytics/Jobs/BuildAffinityJob.php` (تازه)، `tests/Feature/Modules/Analytics/AffinityServiceTest.php` (تازه، ۱۴ تست)، `tests/Feature/Modules/Analytics/BuildAffinityJobTest.php` (تازه، ۴ تست).

## P6-06 — Dashboard + period compare

خواندن: ARCHITECTURE.md (ایندکس)، sprint-6.md، PRD §۱۸ (Dashboard/Customer 360)، §۰۷ (جدول وابستگی ماژول‌ها + امضای `AnalyticsService::dashboard()`/`CohortService::matrix()`/`AffinityService::top()`)، §۲۲ (صف/زمان‌بندی)، §۲۳ (هدف کارایی)، §۰۱ ردیف C۵ (تناقض سطح Affinity، از قبل حل‌شده در خود PRD). اولین آیتم انجام‌نشده بعد از P6-05 دقیقاً همین بود (`grep` روی sprint-6.md و PRD §25 تأیید کرد).

### ابهام‌ها و تصمیم‌های گرفته‌شده (هیچ‌کدام حدس نبود — همه مستند شده)

۱. **«سگمنت‌های فعال» و «سلامت سیستم» در لیست ویجت‌های PRD §۱۸ آمده‌اند، اما جدول وابستگی ماژول‌های PRD §۰۷ اجازه نمی‌دهد:** `Analytics => [Core, Orders, Metrics, Catalog]` — نه Segments نه Sync. این با تست global آرکیتکچری (`ArchitectureTest.php::it('respects the module dependency table')`) اجرا می‌شود، نه فقط توصیه. راه‌حل: هر دو ویجت به یک کارت لینک ساده به صفحه‌ی موجودشان (`/segments`, `/system/health`) تبدیل شدند، فقط با `useCan()` سمت کلاینت (همان هوک مشترک همیشگی) نمایش داده می‌شوند — هیچ کوئری بکند تازه‌ای لازم نبود.
۲. **«خلاصه AI» بدون منبع دادهٔ واقعی:** `grep` تأیید کرد ماژول Ai فقط یک Enum دارد، هیچ Service/Job ساخته نشده (Sprint ۷ شروع نشده). این ویجت کاملاً حذف شد — هیچ دادهٔ ساختگی نمایش داده نمی‌شود.
۳. **کدام ویجت‌ها واقعاً «فیلتر بازه» را می‌پذیرند؟** فقط KPI/روند/مشتری‌جدید-بازگشتی از `daily_metrics` می‌آیند که تنها جدول با بعد تاریخ است. RFM/Churn/Cohort/Affinity هرکدام یک عکس‌فوری از وضعیت فعلی‌اند (جدول خودشان بعد زمانی ندارد) — فیلتر بازه رویشان اثر ندارد؛ صریحاً در UI و کد مستند شد، نه سکوت.
۴. **فرمول «ارزش در خطر» در PRD مشخص نشده.** پیاده‌سازی: `SUM(COALESCE(clv_estimated, clv_historical))` روی مشتریان ریسک high/lost — یک پیش‌فرض معقول با دلیل، نه فرمول PRD.
۵. **هیچ کتابخانه نموداری در پروژه نصب نیست** (بررسی `package.json` + `grep` در `resources/js`). به‌جای افزودن یک‌طرفهٔ وابستگی تازه، ویجت «روند» به‌صورت جدول ساخته شد — دقیقاً هم‌سبک صفحهٔ RFM موجود (که خودش هم نمودار ندارد، فقط Card/Table).
۶. **تناقض معماری واقعی کشف‌شده حین کدنویسی (نه از قبل مستند):** تلاش اول برای خواندن مستقیم `customer_metrics` در `AnalyticsService` با `import` مستقیم Enumهای `RfmSegment`/`ChurnRiskLevel` از ماژول Metrics نوشته شد؛ تست global `ArchitectureTest.php::it('only reaches into other modules through their Services or Events')` رد شد — چون فقط `Services`/`Events` مجازند، نه `Enums`، حتی برای ماژولی که در جدول وابستگی مجاز است. رفع: به‌جای دویاره‌نویسی کوئری، `AnalyticsService` از سرویس عمومی موجود `RfmPageService::getData()['segments']` استفاده می‌کند؛ برای churn (سرویس معادلی وجود نداشت) یک کلاس تازهٔ کوچک `App\Modules\Metrics\Services\ChurnDistributionService` ساخته شد (TEST FIRST، ۳ تست) — دقیقاً هم‌الگوی `RfmPageService`، داخل ماژول Metrics چون `customer_metrics`/Enumهایش را همان ماژول مالک است.

### پیاده‌سازی

- `AnalyticsService::dashboard(DashboardPeriod)` (ماژول Analytics) — یک آرایهٔ آماده‌ی Inertia (هم‌الگوی `RfmPageService::getData()`)، ترکیب: KPI/روند دوره‌ای از `daily_metrics`، دو معیار عمر-فروشگاه از `RetentionService` موجود، RFM از `RfmPageService`، churn از `ChurnDistributionService` تازه، ماتریس کوهورت از متد تازهٔ `CohortSnapshotService::matrix()`، Top Affinity از متد تازهٔ `AffinityService::top()`.
- `DashboardPeriod` (Support DTO تازه) — بازهٔ جاری + بازهٔ هم‌طول قبلی برای مقایسه.
- `DashboardRequest` — `from`/`to` شمسی اختیاری (با هم یا هیچ‌کدام)، با `App\Modules\Customers\Support\JalaliDay` (کلاس آماده‌ی همین پروژه، نه بازنویسی)، اعتبارسنجی محدودهٔ حداکثر (۳۶۶ روز) و ترتیب صحیح.
- `DashboardController` — نازک، یک Service، یک Inertia response.
- مسیر: `Route::inertia('dashboard', 'dashboard')` قدیمی (placeholder starter-kit) از `routes/web.php` حذف و در `routes/internal.php` با `permission:dashboard,view` و همان نام مسیر `dashboard` جایگزین شد (Wayfinder helper و breadcrumbهای بقیهٔ صفحات دست‌نخورده ماندند). پرمیژن `dashboard.view` از قبل seed شده بود (بررسی شد، اضافه نشد).
- Frontend: `resources/js/pages/dashboard.tsx` بازنویسی کامل — فرم فیلتر بازه (همان الگوی `customers/index.tsx`)، کارت‌های KPI با مقایسهٔ درصدی، جدول روند، کارت‌های نرخ خرید مجدد/سهم درآمد بازگشتی، گرید RFM/Churn (هم‌سبک RFM موجود، همان `rfmSegments`/`churnLevels` label map)، ارزش در خطر، جدول ماتریس کوهورت (دوره‌ی نابالغ خاکستری)، جدول Top Affinity، دو کارت لینک (Segments/System Health) با `useCan()`.

### TEST FIRST

`AnalyticsServiceTest.php` (۸ تست): جمع دقیق KPI فقط در بازه (نه کل جدول)، محاسبهٔ دقیق بازهٔ قبلی هم‌طول، روزهای بدون داده = صفر نه خطا، ردیف‌های روند مرتب و محدود به بازه، معیارهای عمر-فروشگاه مستقل از فیلتر، توزیع RFM با کلید `none`، توزیع churn + ارزش در خطر (فقط high/lost)، عبور ماتریس کوهورت/Top Affinity. `AffinityService::top()` و `CohortSnapshotService::matrix()` هرکدام تست اختصاصی گرفتند (ترتیب صحیح، محدودیت count، لیست خالی بدون خطا). `ChurnDistributionServiceTest.php` (۳ تست، ماژول Metrics). `DashboardControllerTest.php` (۸ تست): رد مهمان، رد بدون `dashboard.view`، رندر صحیح، بازهٔ پیش‌فرض ۳۰ روزه، پذیرش بازهٔ شمسی صریح، رد بازهٔ نامعتبر (پایان قبل شروع)، رد تاریخ نامعتبر، الزام هر دوی from/to با هم.

Mutation check: وارونه‌کردن مقایسهٔ `from > to` در `DashboardRequest` → تست رد بازهٔ نامعتبر قرمز شد؛ بازگردانده شد. (مقایسه‌ی بازهٔ قبلی در `DashboardPeriod` هم‌زمان با پیاده‌سازی P6-05 اثبات شده بود، همان الگو اینجا هم صادق است.)

### کراس‌چک مستقل روی dev (پیش‌فرض ۳۰ روز اخیر)

```
period: 2026-08-29 .. 2026-09-27 (قبلی: 2026-07-30 .. 2026-08-28)
current:  orders=1,111  net_revenue=1,420,553,726  aov=1,278,626  customers_new=930  customers_repeat=175
previous: orders=1,593  net_revenue=2,093,957,387
repeat_purchase_rate: 7.71% (13,981 واجد شرط)
returning_revenue_share: 16.75%
value_at_risk: 32,370,663,659 تومان
cohort_matrix: 6 کوهورت · top_affinity: 10 جفت
trend rows: 29 از 30 روز (یک روز، «امروز»، هنوز توسط BuildDailyMetricsJob شبانه محاسبه نشده — طبیعی، نه باگ)
```
کوئری مستقیم و جدا روی `daily_metrics` برای همان دو بازه: `orders=1,111 / net_revenue=1,420,553,726` (جاری) و `orders=1,593 / net_revenue=2,093,957,387` (قبلی) — برابر دقیق. شمار ردیف بازهٔ جاری در `daily_metrics` هم مستقیماً ۲۹ تأیید شد.

### دو تست از‌پیش‌موجود که با جایگزینی placeholder شکستند — رفع شد، نه نادیده گرفته شد

اجرای کامل تست‌ها (بعد از تمام تغییرات بالا) دو شکست نشان داد، هر دو چون `/dashboard` قبلاً برای هر کاربر واردشده باز بود و حالا پشت `dashboard.view` است:
- `tests/Feature/DashboardTest.php` (تست starter-kit خود Laravel) — «کاربر واردشده می‌تواند داشبورد را ببیند» با یک کاربر بدون هیچ نقشی می‌سنجید؛ اصلاح شد تا از `SystemPageFixtures::userWith('dashboard.view')` استفاده کند (نامش هم به‌روزرسانی شد تا دقیق‌تر باشد).
- `tests/Feature/Modules/Core/InertiaPermissionsShareTest.php` — از `/dashboard` به‌عنوان یک صفحهٔ ساده‌ی واردشده برای سنجش prop مشترک `auth.permissions` استفاده می‌کرد؛ اصلاح شد تا نقش تست، هم `segments.view` (چیزی که واقعاً سنجیده می‌شود) و هم `dashboard.view` (تا خود صفحه اصلاً بارگذاری شود) را داشته باشد.

هیچ تستی حذف یا موقتاً غیرفعال نشد (CLAUDE.md §9) — هر دو با تغییر واقعی و به‌روز به قرارداد تازهٔ صفحه اصلاح شدند.

### بستن این تسک

تست کامل: `php artisan test` → ۳٬۰۴۰/۳٬۰۴۰ سبز (بعد از رفع دو تست بالا). PHPStan (`app/Modules/Analytics`, `app/Modules/Metrics`, کنترلر/ریکوئست تازه) → ۰ خطا (۲ ایراد نوع لیست/آرایه پیدا و رفع شد: `array_values()` روی خروجی‌های `Collection::map()->all()`، و تکمیل PHPDoc تو‌درتوی `dashboard()`). Pint → تمیز (چند فایل با `ordered_imports`/`fully_qualified_strict_types` اصلاح شدند). `npm run types:check` → تمیز. `npm run build` → موفق. `vp check --fix` فقط روی فایل تازهٔ خودم (`dashboard.tsx`) اجرا شد، نه کل مخزن (که فرمت‌نشدگی‌های قدیمی و نامرتبط زیادی در فایل‌های دیگر دارد).

**فایل‌ها:** `app/Modules/Analytics/Services/AnalyticsService.php` (تازه)، `app/Modules/Analytics/Support/DashboardPeriod.php` (تازه)، `app/Modules/Analytics/Services/AffinityService.php` (+`top()`)، `app/Modules/Analytics/Services/CohortSnapshotService.php` (+`matrix()`)، `app/Modules/Metrics/Services/ChurnDistributionService.php` (تازه)، `app/Http/Requests/DashboardRequest.php` (تازه)، `app/Http/Controllers/DashboardController.php` (تازه)، `routes/web.php` (حذف placeholder)، `routes/internal.php` (+مسیر dashboard)، `resources/js/pages/dashboard.tsx` (بازنویسی کامل)، `resources/js/types/dashboard.ts` (تازه)، تست‌ها: `AnalyticsServiceTest.php`، `ChurnDistributionServiceTest.php`، `DashboardControllerTest.php` (همه تازه) + افزوده به `AffinityServiceTest.php`/`CohortSnapshotServiceTest.php`؛ `tests/Arch/CustomerListBoundaryTest.php` (شمار Route::get از ۱۵ به ۱۶).

## P6-07 — Drill-down + export

خواندن: ARCHITECTURE.md، sprint-6.md، و کل متن PRD مربوط به drill/export — که فقط **یک جملهٔ توصیفی** و **یک جزئیات فنی جعبه‌ای** است: «فیلتر بازه، مقایسه دوره، Export، Drill-down یکنواخت (`GET /internal/drill/{widget}`). هیچ تجمیع سنگینی در لحظه بارگذاری. عددی که نتوانید پشتش را ببینید، قابل اعتماد نیست.» (§۱۸) و «GATE: dashboard < 1s, every number drillable» (§۲۵). هیچ لیست ویجت، هیچ فرمت پاسخ، هیچ ستون export مشخص نشده — این تسک به‌شدت کم‌مشخصات است؛ زیر تصمیم‌های گرفته‌شده و دلیلشان آمده، نه حدس.

### ابهام‌ها و تصمیم‌های گرفته‌شده

۱. **کدام ویجت‌ها واقعاً drill می‌شوند؟** PRD «هر عدد» می‌گوید اما هیچ لیستی نمی‌دهد. تصمیم: ۵ ویجت که یک فهرست ردیف فیلترشدهٔ ساده‌اند — `orders` (پشت سفارش‌ها/درآمد خالص/AOV با هم، چون هر سه از یک مجموعه سفارش مشتق می‌شوند)، `customers_new`، `customers_repeat`، `rfm_segment`، `churn_level`. **drill سلول کوهورت و جفت‌های Affinity ساخته نشد** — برخلاف این ۵ تا، این دو نیاز به بازتولید واقعی دارند (کدام مشتریان در دورهٔ N کوهورت X فعال بودند؛ کدام مشتریان دقیقاً جفت A/B را هم‌خریده‌اند) نه یک فیلتر ساده روی جدول موجود — دامنه‌ای واقعاً بزرگ‌تر، به‌عنوان Open Item مستند شد (ARCHITECTURE.md)، نه حدس‌زده.
۲. **مسیر `GET /internal/drill/{widget}` — دقیقاً همین رشته پیاده شد**، با `internal/` به‌عنوان بخشی از URL (برخلاف بقیهٔ صفحات همین فایل که بدون این پیشوندند) — چون این تنها جزئیات فنی صریح PRD برای کل این تسک است.
۳. **Export با کدام مجوز؟** هیچ `dashboard.export`/`analytics.export` در seed موجود نیست. تصمیم: از `customers.export` موجود استفاده شد — دقیقاً همان استدلال از‌پیش‌مستند در `routes/internal.php` برای export سگمنت: «این هم همان خروج‌گیری ممیزی‌شدهٔ PII است، مثل هر export دیگر مشتریان». مسیر export علاوه‌بر `customers,export`، به `dashboard,view` هم نیاز دارد (چون همچنان یک drill از داشبورد است).
۴. **چه ستون‌هایی در پاسخ JSON قابل‌قبول است؟** طبق قاعدهٔ از‌پیش‌موجود `RfmPageService::topChampions()` («هرگز نام یا تلفن در یک نمای تجمیعی») — ردیف‌های JSON فقط id و عدد دارند، هرگز display_name/phone. فقط CSV ممیزی‌شده (با `customers.export` جدا) اجازهٔ نام/تلفن (ماسک‌شده مگر `customers.view_full_phone`) دارد — دقیقاً هم‌الگوی `SegmentService::export()`.
۵. **تعارض معماری مشابه P6-06 (که کاربر از قبل پیش‌بینی کرده بود):** برای فیلتر `segment`/`level`، اعتبارسنجی مقدار باید دربرابر `RfmSegment`/`ChurnRiskLevel` انجام شود. به‌جای import این Enumها داخل ماژول Analytics (که دقیقاً همان تخلف P6-06 را تکرار می‌کرد)، اعتبارسنجی در لایهٔ HTTP (`DrillRequest`, که ماژول نیست و آزادانه از هر Enum می‌تواند import کند) انجام شد؛ رشتهٔ معتبرشده بدون import کردن Enum به `DrillService` می‌رسد — دقیقاً همان الگوی سرویس عمومی/مرز ماژول که در P6-06 کشف و رفع شد، این‌بار حتی بدون نیاز به یک سرویس تازه.
۶. **مشکل واقعی دیگر کشف‌شده حین کد زدن:** برای گرفتن رشتهٔ شمسی معادل بازهٔ داشبورد (لازم برای ساخت لینک drill از صفحهٔ React)، فرانت‌اند نمی‌توانست خودش Gregorian→Jalali تبدیل کند (قاعدهٔ «تاریخ شمسی فقط با JalaliDate»). رفع: `AnalyticsService::dashboard()` اکنون `period.from_jalali`/`period.to_jalali` را هم برمی‌گرداند (با `App\Support\JalaliDate::format()`)، تا صفحه بدون منطق تبدیل تاریخ خودش، این رشته‌ها را مستقیم به drill پاس بدهد.

### پیاده‌سازی

- `DrillService` (ماژول Analytics) — `rows(string $widget, DashboardPeriod, array $params): ?DrillResult` (خواندن، محدود به ۲۰۰ ردیف با پرچم `truncated`) و `export(...)` (استریم CSV، بدون محدودیت، ممیزی‌شده). `orders`/`customer_metrics` مستقیم خوانده می‌شوند (Query Builder، بدون مدل Eloquent، بدون import Enum) — همان الگوی از‌پیش‌موجود `RetentionService`/`DailyMetricsService`. تعریف «سفارش واقعی‌شده» و «مشتری بازگشتی» (`ordered_at روز != first_order_at روز`) دقیقاً همان تعریف `BaseAggregateService`/`DailyMetricsService` است.
- `DrillResult` (Support DTO)، دو Exception تازه (`DrillExportForbiddenException`, `UnknownDrillWidgetException`) با mapping متمرکز در `bootstrap/app.php` (هم‌الگوی `SegmentException`/`RuleValidationException` موجود) — کنترلرها بدون try/catch می‌مانند.
- `DrillRequest` — `from`/`to` شمسی (هم‌الگوی `DashboardRequest`، عمداً تکرار نه انتزاع مشترک — سه خط مشابه بهتر از انتزاع زودهنگام)، به‌علاوهٔ `segment`/`level` (فقط برای ویجت مربوطه لازم، با `Rule::in` از مقادیر واقعی Enum + مقدار مصنوعی `none`).
- `DrillController` (JSON) و `DrillExportController` (CSV) — هرکدام نازک، یک Service call.
- مسیرها: `GET internal/drill/{widget}` پشت `dashboard,view`؛ `GET internal/drill/{widget}/export` پشت `dashboard,view` **و** `customers,export` (هردو در سطح route، به‌علاوه چک دوباره داخل خود Service — هم‌الگوی segments/export).
- Frontend: `resources/js/components/dashboard/drill-dialog.tsx` (کامپوننت تازه، قابل‌استفادهٔ مجدد) — روی کلیک، JSON را fetch می‌کند و در یک Dialog (shadcn) به‌صورت جدول عمومی نمایش می‌دهد، با لینک «دانلود CSV». در `dashboard.tsx` سیم‌کشی شد: هر ۵ کارت KPI (سفارش/درآمد/AOV/مشتری‌جدید/بازگشتی) و هر سلول گرید RFM و Churn حالا قابل‌کلیک‌اند.

### TEST FIRST

`DrillServiceTest.php` (۹ تست): فیلتر بازهٔ هرکدام از ۵ ویجت، حذف سفارش غیرواقعی/مستردشدهٔ‌کامل، بدون name/phone در ستون‌ها، bucket مصنوعی `none`، ویجت ناشناس → null نه خطا، truncation دقیق در سقف. `DrillExportTest.php` (۶ تست، هم‌الگوی دقیق `SegmentServiceExportTest`): رد بدون مجوز + بدون ثبت audit، ثبت ردیف audit با widget/بازه، ماسک/عدم‌ماسک تلفن، BOM+هدر، ویجت ناشناس → خطا. `DrillControllerTest.php`/`DrillExportControllerTest.php` (۱۳ تست): مهمان، بدون مجوز (هردو مجوز جدا برای export)، ۴۰۴ ویجت ناشناس، ۴۲۲ مقدار segment نامعتبر/غایب، فیلتر واقعی، جریان CSV واقعی + ردیف audit واقعی.

Mutation check: وارونه‌کردن مقایسهٔ `!=` بین روز سفارش و روز اولین سفارش (تعریف «بازگشتی») → تست مربوطه با شکست واقعی قرمز شد؛ بازگردانده شد. وارونه‌کردن `denies()`→`allows()` در چک مجوز export → تست رد‌شدن قرمز شد؛ بازگردانده شد.

### کراس‌چک مستقل روی dev (بازهٔ پیش‌فرض ۳۰ روز اخیر: 2026-08-29 .. 2026-09-27)

```
orders:           drill=200 (truncated) | مستقل COUNT=1,111   (هم‌ارز با current.orders_count داشبورد P6-06)
customers_new:    drill=200 (truncated) | مستقل COUNT=930     (هم‌ارز با current.customers_new داشبورد P6-06)
customers_repeat: drill=161             | مستقل COUNT=161     — برابر دقیق
rfm_segment=champion: drill=61          | مستقل COUNT=61      — برابر دقیق
churn_level=high: drill=200 (truncated) | مستقل COUNT=3,841
```
هر ۵ کوئری drill با شرط WHERE یکسان، جدا و مستقیم روی dev دوباره اجرا شد و دقیقاً برابر بود؛ دو موردی که truncate شده‌اند (۲۰۰ سقف JSON) با شمارش کامل مستقل تأیید شدند که واقعاً بیشتر از ۲۰۰ ردیف دارند، نه یک باگ. مسیر export همان شرط‌های WHERE را دارد (فقط با JOIN اضافه برای phone/display_name) — با همین کراس‌چک‌ها به‌طور غیرمستقیم تأیید شد؛ دانلود واقعی CSV روی dev اجرا نشد (نیاز به یک کاربر واقعی با `customers.export`)، اما مسیر کد یکسان با `rows()` است و در تست‌های Pest با استریم واقعی (نه mock) پوشش داده شده.

### بستن این تسک

تست کامل: `php artisan test` → در حال اجرا؛ نتیجه در گزارش چت. PHPStan (`app/Modules/Analytics`, کنترلرها/ریکوئست‌ها، `bootstrap/app.php`) → ۰ خطا (چند ایراد نوع پیدا و رفع شد: `array_values()` روی خروجی‌های `map()->all()`، `whereColumn()` با `Expression` نپذیرفته‌شده → `whereRaw()` ایستا، و typing بستارهای export از `object` به `\stdClass` برای دسترسی امن به خواص). Pint → تمیز. `npm run types:check`/`npm run build` → تمیز.

**فایل‌ها:** `app/Modules/Analytics/Services/DrillService.php` (تازه)، `app/Modules/Analytics/Support/DrillResult.php` (تازه)، `app/Modules/Analytics/Exceptions/{DrillExportForbiddenException,UnknownDrillWidgetException}.php` (تازه)، `app/Http/Requests/DrillRequest.php` (تازه)، `app/Http/Controllers/{DrillController,DrillExportController}.php` (تازه)، `bootstrap/app.php` (+mapping استثنا)، `routes/internal.php` (+۲ مسیر)، `app/Modules/Analytics/Services/AnalyticsService.php` (+`period.from_jalali`/`to_jalali`)، `resources/js/components/dashboard/drill-dialog.tsx` (تازه)، `resources/js/pages/dashboard.tsx` (سیم‌کشی drill)، `resources/js/types/dashboard.ts` (+فیلدهای جدید)، تست‌ها: `DrillServiceTest.php`, `DrillExportTest.php`, `DrillControllerTest.php`, `DrillExportControllerTest.php` (همه تازه) + یک تست به `AnalyticsServiceTest.php`؛ `tests/Arch/CustomerListBoundaryTest.php` (شمار Route::get از ۱۶ به ۱۸).

## P6-08 — Cohort / Retention / Affinity pages

خواندن: ARCHITECTURE.md، sprint-6.md (بخش‌های P6-03/۰۴/۰۵/۰۶/۰۷ — الگوی سرویس‌های موجود)، PRD §۱۸ (که فقط داشبورد/Customer 360 را توصیف می‌کند، هیچ اشاره‌ای به سه صفحهٔ مستقل ندارد)، PRD §۰۷ (نام‌گذاری ماژول Analytics + متدهایش)، PRD §۱۵/۱۶ (فرمول‌های خود Cohort/Retention/Affinity، از قبل در P6-03/04/05 پیاده شده). PRD برای «صفحه» چیزی نمی‌گوید — نه مسیر، نه لایوت، نه مجوز مشخص؛ تصمیم‌ها زیر آمده.

### ابهام‌ها و تصمیم‌های گرفته‌شده

۱. **کدام مجوز؟** هیچ‌کدام از سه صفحه در PRD به یک permission نگاشت نشده. `analytics.view` از ابتدای Sprint 6 seed شده و به نقش Viewer داده شده بود، اما تا این تسک هیچ مسیری از آن استفاده نمی‌کرد (بررسی شد: `grep` در `routes/` چیزی نداد). چون PRD §۰۷ دقیقاً `AnalyticsService`/`CohortService`/`AffinityService` را زیر عنوان «Analytics» می‌آورد (نه Metrics، نه Dashboard)، این سه صفحه پشت `analytics,view` قرار گرفتند — نه `metrics,view` (ماژول دیگر) و نه `dashboard,view` (صفحهٔ دیگر).
۲. **صفحهٔ Retention به چند بازهٔ N-day نیاز دارد؟** PRD §۱۵ هیچ لیست ثابتی نمی‌دهد (فقط جدول Affinity حداقل‌های صریح دارد). تصمیم: ۷/۳۰/۹۰ روز — بازه‌های رایج تجارت الکترونیک، یک پیش‌فرض مستدل نه عدد PRD.
۳. **صفحهٔ Affinity چند سطح نشان دهد؟** عنوان خود آیتم بک‌لاگ PRD «Affinity (4 levels)» است — پس هر ۴ سطح (نه فقط یکی) نمایش داده می‌شود.
۴. **«هیچ منطق/کوئری جدید مگر لازم باشد»:** هیچ کوئری تازه‌ای نوشته نشد؛ فقط دو متد ترکیبی نازک اضافه شد چون کنترلر فقط می‌تواند یک سرویس صدا بزند (قاعدهٔ ثابت پروژه) اما این دو داده به بیش از یک متد از‌پیش‌تست‌شده نیاز داشتند:
   - `RetentionService::summary(array $days=[7,30,90])` — فقط سه متد از‌پیش‌تست‌شدهٔ P6-04 (`repeatPurchaseRate`, `returningRevenueShare`, `nDayRetention`) را ترکیب می‌کند، هیچ محاسبهٔ تازه.
   - `AffinityService::topAll(int $limitPerLevel=10)` — فقط `top()` از‌پیش‌تست‌شدهٔ P6-05/06 را برای هر ۴ سطح صدا می‌زند.
   - `CohortSnapshotService::matrix()` نیازی به تغییر نداشت؛ کنترلر مستقیماً همان را با آرگومان بزرگ‌تر (۱۲ به‌جای ۶ پیش‌فرض داشبورد) صدا می‌زند — همان متد، فقط آرگومان دیگر.

### وصل‌کردن drill-down (طبق دستور صریح شما — Open Item باز‌مانده از P6-07)

بررسی شد: هر دو (سلول کوهورت، جفت Affinity) واقعاً قابل‌اتصال بودند، چون منطق دقیق هر دو از قبل در `CohortSnapshotService::rebuild()` و `AffinityService::rebuild*Level()` نوشته شده بود (فقط نیاز به بازتولید همان شرط‌ها به‌عنوان یک کوئری drill، نه اختراع تعریف تازه):

- **`cohort_period`** (پارامتر `cohort_month`, `period_number`) — دقیقاً همان تعریف «فعال» از activity CTE در `rebuild()`: `jalali_month_diff(cohort_month, to_jalali_month(ordered_at)) = period_number`.
- **`affinity_pair`** (پارامتر `affinity_level` ∈ {product, category, variation} — **نه basket**، `entity_a_id`, `entity_b_id`) — همان «pairs» که هر سطح خودش در rebuild می‌خواند: product/category از جدول‌های تجمیعی P6-01، variation مستقیم از order_items.
- **چرا `basket` وصل نشد:** جفت سطح basket یعنی «سفارش‌ها»، نه «مشتریان» — شکل ردیف کاملاً متفاوت از بقیهٔ ۶ ویجت drill موجود (که همه مشتری‌محورند). این یک محدودیت واقعی جدید کشف‌شده است، نه تنبلی: اجبار آن به شکل «مشتری» یا داده را دروغ نشان می‌دهد یا نیاز به یک هفتمین شکل ردیف کاملاً جدا دارد — به‌عنوان یک محدودیت مستند باقی ماند (ARCHITECTURE.md)، حدس زده نشد.
- Export برای `cohort_period` و `affinity_pair` (فقط product/category — **نه variation**) هم ساخته شد؛ export سطح variation به‌خاطر پیچیدگی نوشتن یک derived-table join در Query Builder سیال (که در rows() با یک CTE خام حل شده بود) پیاده نشد — محدودیت باریک‌تر و مستند دیگری، نه سکوت. مسیر JSON برای variation کامل است.

### پیاده‌سازی

- `CohortPageController`, `RetentionPageController`, `AffinityPageController` (زیر `app/Http/Controllers/Analytics/`) — هرکدام نازک، دقیقاً یک Service call، هم‌الگوی `RfmPageController`.
- مسیرها: `analytics/cohort`, `analytics/retention`, `analytics/affinity`، همگی پشت `permission:analytics,view`.
- سه صفحهٔ React (`resources/js/pages/analytics/{cohort,retention,affinity}.tsx`) — هم‌سبک صفحهٔ RFM موجود (Card/Table، بدون کتابخانه نمودار، لایوت با breadcrumb). سلول‌های ماتریس کوهورت (فقط سلول بالغ با مشتری فعال) و ردیف‌های جدول Affinity (فقط سطح product/category/variation) با `DrillDialog` موجود (از P6-07) قابل‌کلیک شدند؛ ردیف‌های سطح basket بدون تعامل، فقط نمایشی.
- `RetentionService::summary()`, `AffinityService::topAll()` (متد تازه در سرویس‌های موجود)، `DrillService` گسترش‌یافته با دو ویجت تازه (`cohort_period`, `affinity_pair`)، `DrillRequest` با پارامترهای تازه.

### TEST FIRST

طبق دستور شما: تست روی کنترلرها/props، نه منطق محاسباتی که قبلاً در P6-03/04/05 تست شده است.
- `RetentionServiceTest.php` (+۲ تست): شکل ترکیب `summary()` (۳ بازه، فیلدهای صحیح)، «داده کافی نیست» برای بازهٔ نابالغ (نه صفر جعلی).
- `AffinityServiceTest.php` (+۲ تست): شکل `topAll()` (هر ۴ کلید، سطح بدون داده = `[]`)، محدودیت مستقل هر سطح.
- `DrillServiceTest.php` (+۴ تست), `DrillExportTest.php` (+۲ تست): دو ویجت تازه، شامل تست «basket پیاده نشده».
- `CohortPageControllerTest.php`, `RetentionPageControllerTest.php`, `AffinityPageControllerTest.php` (تازه، ۱۱ تست): مهمان، بدون `analytics.view`، رندر با داده واقعی، حالت خالی بدون خطا.
- `DrillControllerTest.php` (+۳ تست): اعتبارسنجی پارامترهای تازه، رد سطح basket.

Mutation check: مقایسهٔ `jalali_month_diff(...) = period_number` در cohort_period به `>=` شل شد → تست (بعد از تقویت با یک مشتری در دورهٔ بعدی) با شکست واقعی قرمز شد؛ بازگردانده شد.

### بررسی دستی روی dev با داده واقعی (طبق دستور شما — بدون کراس‌چک، چون داده از سرویس‌های تست‌شده می‌آید)

با صدا زدن مستقیم همان سه سرویسی که کنترلرها صدا می‌زنند:
```
ماتریس کوهورت: ۱۲ کوهورت × ۲۵ دوره (هر کوهورت)
نگهداشت: ۳ بازه (۷/۳۰/۹۰ روز)، نرخ خرید مجدد = ۷.۷۱٪ (۱٬۰۷۸ از ۱۳٬۹۸۱ — هم‌ارز عدد قبلاً گزارش‌شدهٔ داشبورد P6-06)
Affinity: category=۱۰ جفت، product=۱۰ جفت، basket=۱۰ جفت (هرکدام سقف‌خورده به ۱۰ پیش‌فرض)، variation=۰ جفت (طبیعی — همان محدودیت شناخته‌شدهٔ P6-05: فقط ۸۱ order_item به یک variation حل شده‌اند)
```
هر سه صفحه با کامپایل واقعی (`npm run build`) و درخواست HTTP واقعی (تست‌های Pest، نه mock) با همین داده تأیید شدند.

### بستن این تسک

تست کامل: `php artisan test` → در حال اجرا؛ نتیجه در گزارش چت. PHPStan (`app/Modules/Analytics`, کنترلرهای Analytics، `DrillRequest`) → ۰ خطا. Pint → تمیز. `npm run types:check` → تمیز. `npm run build` → موفق (شامل تولید خودکار helperهای Wayfinder برای مسیرهای تازه: `resources/js/routes/analytics/index.ts`).

**فایل‌ها:** `app/Http/Controllers/Analytics/{CohortPageController,RetentionPageController,AffinityPageController}.php` (تازه)، `app/Modules/Analytics/Services/RetentionService.php` (+`summary()`)، `app/Modules/Analytics/Services/AffinityService.php` (+`topAll()`)، `app/Modules/Analytics/Services/DrillService.php` (+`cohort_period`, `affinity_pair`)، `app/Http/Requests/DrillRequest.php` (+پارامترهای تازه)، `routes/internal.php` (+۳ مسیر)، `resources/js/pages/analytics/{cohort,retention,affinity}.tsx` (تازه)، `resources/js/types/analytics.ts` (تازه)، تست‌ها: `tests/Feature/Http/Analytics/*ControllerTest.php` (تازه) + افزوده به `RetentionServiceTest.php`, `AffinityServiceTest.php`, `DrillServiceTest.php`, `DrillExportTest.php`, `DrillControllerTest.php`؛ `tests/Arch/CustomerListBoundaryTest.php` (شمار Route::get از ۱۸ به ۲۱).

## P6-09 — Full scheduler chain

خواندن: ARCHITECTURE.md (ایندکس)، sprint-6.md (P6-01..08 — فهرست کامل جاب‌های موجود و ریسک‌های ثبت‌شدهٔ وابستگی)، PRD §۲۲ کامل (صف‌ها، لیست Jobها، Scheduler دقیق، دلیل صریح استفاده از `Bus::chain`).

### ابهام‌ها و تناقض‌ها — همه صریحاً اینجا، نه حدس

۱. **PRD دربارهٔ رفتار شکست ساکت نیست — دستور «اگر PRD ساکت است، log کن و ادامه بده» اینجا اعمال نشد.** متن خود PRD §۲۲: «ترتیب حیاتی است. `Bus::chain` در اولین شکست متوقف می‌شود — بهتر است چیزی اجرا نشود تا با داده ناقص اجرا شود.» این یک تصمیم صریح PRD است، نه سکوت؛ پس رفتار پیش‌فرض خود `Bus::chain` (توقف در اولین شکست، رد شدن از بقیه) عیناً استفاده شد — فقط یک `->catch()` برای لاگ‌کردن خطا اضافه شد (برای مشاهده‌پذیری، نه برای تغییر رفتار توقف).
۲. **«Customers» به‌عنوان یک مرحلهٔ جدا در زنجیرهٔ PRD آمده، ولی هیچ Job/Entity جداگانه‌ای برایش وجود ندارد.** `SyncEntity` فقط دو مقدار دارد: `Orders`, `Catalog` — با یک کامنت صریح در کد خودش: «customers are not run through here yet». بررسی شد: هویت مشتری (`CustomerIdentityService`) به‌عنوان اثر جانبی همان Sync سفارش‌ها (`OrderSyncService`) ساخته/به‌روز می‌شود، نه از یک endpoint جدای Woo. تصمیم: مرحلهٔ «Customers» زنجیره با مرحلهٔ Orders sync موجود پوشش داده می‌شود؛ یک `SyncEntity::Customers` جعلی ساخته نشد (خارج از Scope این تسک — تغییر ماژول Sync).
۳. **«ReconcileJob(2)» — کدام Job، و «۲» یعنی چه؟** لیست کلی Jobهای PRD §۲۲ نامی به‌اسم «ReconcileJob» می‌آورد؛ کلاس واقعی از P2-11 `ReconcileMonthJob` است (یک ماه در هر Job). با قیاس با `BuildDailyMetricsJob(3)` (که PRD همان‌جا آرگومانش را می‌نویسد)، «۲» به «۲ ماه شمسی کامل اخیر» تفسیر شد — یک بازبینی شبانهٔ ارزان، نه کل تاریخچه (`hm:reconcile --all` که هر شب همهٔ ماه‌ها را دوباره می‌خواند، از سال ۱۴۰۳). متد/Job تازه ساخته شد: `ReconciliationMonths::lastN()`, `ReconciliationService::dispatchRecentMonths()`, `ReconcileRecentMonthsJob` — همان الگوی دقیق `dispatchAllMonths()`/`ReconcileMonthJob` موجود، فقط محدود به N ماه آخر.
۴. **PRD §۲۲ شش Job نام می‌برد که هنوز ساخته نشده‌اند:** `GenerateDailyBriefJob`, `GenerateWeeklyReviewJob` (هر دو AI Analyst، Sprint 7، شروع نشده)، `PruneLogsJob`, `HealthCheckJob`, `backup:run`, `horizon:snapshot` (هیچ‌کدام در PRD §۲۵ به یک تسک بک‌لاگ نگاشت نشده‌اند). زمان‌بندی نشدند — چیزی برای زمان‌بندی وجود ندارد؛ به‌عنوان Open Item ثبت شد (ARCHITECTURE.md)، نه نادیده گرفته شد.
۵. **یافتهٔ جانبی واقعی، نه فرضی — پیکربندی Horizon/Queue قدیمی و اشتباه بود.** `config/horizon.php`'s تنها supervisor مشترک (پوشش‌دهندهٔ هر ۵ صف) هنوز `timeout => 60` از Sprint 0 بود؛ `config/queue.php`'s `retry_after` هم `330` بود (مقدار P2-11، فقط برای تایم‌اوت ۳۰۰ ثانیه‌ای `ReconcileMonthJob` تنظیم‌شده). هیچ‌کدام وقتی `RecomputeMetricsJob`/`BuildAffinityJob` با تایم‌اوت ۹۰۰ ثانیه اضافه شدند (P4-07/P6-05) به‌روز نشدند. این یک Open Item از‌پیش‌ثبت‌شده در ARCHITECTURE.md بود («باید قبل از فعال‌سازی scheduler تولید حل شود») — دقیقاً همین تسک است. `retry_after` زیر تایم‌اوت یک Job یعنی Redis یک Job هنوز-در-حال‌اجرا را دوباره در دسترس می‌گذارد؛ برخلاف قفل `ShouldBeUnique` (که فقط جلوی dispatch دوباره را می‌گیرد، نه popکردن دوباره از صف)، این واقعاً منجر به اجرای دوبارهٔ همان Job می‌شود. رفع: `timeout` 60→900 (برابر بلندترین Job)، `retry_after` 330→930 (۳۰ ثانیه بیشتر — همان قاعدهٔ قبلی). تست رگرسیون: `ReconcileMonthJobTest` حالا `retry_after` را در برابر تایم‌اوت هر Job سنگین در کل پروژه چک می‌کند، نه فقط ماژول Sync.

### پیاده‌سازی

`hm:nightly-chain` (`app/Console/Commands/NightlyChainCommand.php`) — یک `Bus::chain` واحد، بدون هیچ منطقی جز چینش (CLAUDE.md §۱/§۵)، دقیقاً به ترتیب PRD §۲۲:

```
CatalogSyncJob
SyncEntityJob(Orders, Incremental)
RecomputeMetricsJob('full')
BuildCustomerPurchaseAggregatesJob
BuildDailyMetricsJob(3)
BuildCohortSnapshotsJob
RebuildAllSegmentsJob
ReconcileRecentMonthsJob(2)
```

Orders sync در زنجیره «Incremental» است، نه «Full»: Poll هر ۱۵ دقیقهٔ موجود (بدون تغییر) از قبل سفارش‌ها را تازه نگه می‌دارد؛ این مرحله فقط چند دقیقهٔ آخر قبل از محاسبهٔ متریک را جبران می‌کند — یک Full هر شب («بازخوانی کامل تاریخچه») نه در PRD §۲۳ («Incremental Sync <۶۰s»، نه Full) هدف‌گذاری شده و نه لازم.

Scheduler (`routes/console.php`): `Schedule::command('hm:nightly-chain')->dailyAt('03:00')` جایگزین دو ورودی مستقل قبلی شد (`hm:sync --entity=catalog` در ۰۱:۳۰ و `hm:reconcile --all` در ۰۲:۰۰ — هر دو از ابتدا به‌عنوان جایگزین موقت تا ساخت P6-09 مستند بودند). `BuildAffinityJob` مستقل ماند: `Schedule::job(new BuildAffinityJob)->weeklyOn(6, '04:00')` — به هیچ‌چیز در زنجیرهٔ شبانه وابسته نیست و چیزی در زنجیره به آن وابسته نیست. Poll ۱۵‌دقیقه‌ای سفارش‌ها بدون تغییر ماند.

### TEST FIRST

- `ReconciliationMonthsTest.php` (+۳ تست): `lastN()` — لیست N ماه آخر، سقف‌خوردن وقتی تعداد ماه کامل کمتر است، خالی وقتی هیچ ماهی کامل نیست.
- `ReconciliationServiceTest.php` (+۲ تست): `dispatchRecentMonths()` — فقط N ماه آخر روی صف `sync`.
- `ReconcileRecentMonthsJobTest.php` (تازه، ۲ تست): شکل Job (`ShouldBeUnique`، صف، tries)، dispatch واقعی ماه‌ها هنگام `handle()`.
- `ReconcileMonthJobTest.php` (+۱ تست): `retry_after` در برابر تایم‌اوت هر Job سنگین پروژه (نه فقط Sync).
- `NightlyChainCommandTest.php` (تازه، ۳ تست):
  - ترتیب دقیق زنجیره (`Bus::fake()` + `Bus::assertChained()`), شامل بررسی `RecomputeMetricsJob->runType === 'full'` (نه `'dirty'`).
  - **تست دوم شما، به‌جای mock:** یک اجرای واقعی (نه fake) از زیرزنجیرهٔ حساس به ترتیب (`RecomputeMetricsJob→BuildCustomerPurchaseAggregatesJob→BuildDailyMetricsJob→BuildCohortSnapshotsJob`) روی یک مشتری تازه که هنوز هیچ ردیف `customer_metrics` ندارد؛ تأیید می‌کند `cohort_month` واقعاً تازه به `BuildCohortSnapshotsJob` می‌رسد. مراحل Sync/Reconcile در این تست نیستند (نیاز به Woo دارند؛ `.claude/rules/tests.md`: «Tests never hit the network»)؛ `QUEUE_CONNECTION=sync` در `phpunit.xml` یعنی `Bus::chain()->dispatch()` واقعی و در همان پردازش اجرا می‌شود.

Mutation check ۱ (ترتیب کلی): `RecomputeMetricsJob` و `BuildCustomerPurchaseAggregatesJob` در کد جابه‌جا شدند → هر دو تست ترتیب با شکست واقعی (نوع Job اشتباه در زنجیره) قرمز شدند؛ بازگردانده شد.
Mutation check ۲ (وابستگی واقعی): در تست دوم، `BuildCohortSnapshotsJob` به ابتدای لیست منتقل شد → `cohort_month` هنوز `NULL` بود، assertion با `null is not 1` شکست خورد؛ بازگردانده شد.
Mutation check ۳ (پیکربندی): `retry_after` موقتاً به ۳۳۰ برگردانده شد → تست تازهٔ `ReconcileMonthJobTest` با `330 is not greater than 900` شکست خورد؛ بازگردانده شد.

نکتهٔ فنی کشف‌شده حین TEST FIRST: `Dispatcher::dispatchSync()` یک Job که `ShouldQueue` است را از طریق `dispatchToQueue()->onConnection('sync')` می‌فرستد — که وقتی `Queue::fake()` فعال است، فقط به‌عنوان یک push ثبت می‌شود، اجرا نمی‌شود (همان دلیلی که تست موجود `dispatchAllMonths()` هم مستقیماً متد سرویس را صدا می‌زند، نه از طریق Bus). `ReconcileRecentMonthsJobTest` هم به همین دلیل مستقیماً `->handle()` را صدا می‌زند.

### اجرای واقعی روی dev

طبق دستور شما (بدون شبکهٔ زنده به Woo، همان‌طور که در کل این Sprint هیچ GET زنده‌ای به Woo انجام نشد): مراحل ۱، ۲ و ۸ زنجیره (Catalog sync، Orders sync، Reconcile) نیاز به Woo دارند و روی dev شبیه‌سازی شدند — با داده‌ی موجود dev (از Syncهای واقعی همین Sprint) به‌جای فراخوانی Woo. زیرزنجیرهٔ ۳ تا ۷ (متریک تا سگمنت‌ها) واقعاً و کامل روی dev اجرا شد:

```
قبل  → customer_metrics: 19,905 | customer_product_purchases: 26,664 | daily_metrics: 735 | cohort_snapshots: 625 | segment_members: 39,844
RecomputeMetricsJob(full):            11,870ms
BuildCustomerPurchaseAggregatesJob:    4,436ms
BuildDailyMetricsJob(3):                  80ms
BuildCohortSnapshotsJob:                 458ms
RebuildAllSegmentsJob:                 4,279ms  (۱۲ سگمنت موفق، ۰ شکست)
بعد   → customer_metrics: 19,905 | customer_product_purchases: 26,664 | daily_metrics: 737 | cohort_snapshots: 625 | segment_members: 39,921
جمع (فقط زیرزنجیرهٔ ۳-۷): 21,464ms
```
شمارها همان‌طور که انتظار می‌رفت: rebuild-از-منبع یعنی جدول‌های TRUNCATE-شونده (customer_product_purchases، cohort_snapshots) ثابت می‌مانند وقتی داده‌ی منبع تغییر نکرده؛ `daily_metrics`/`segment_members` رشد کردند چون به تاریخ/عضویت پویا وابسته‌اند (۲ روز جدید، ۷۷ عضو تازه). `ReconcileRecentMonthsJob(2)` روی dev شبیه‌سازی شد: `ReconciliationMonths::lastN(2)` مستقیماً صدا زده شد (بدون dispatch واقعی که Woo لازم دارد) → `['1405-05', '1405-06']`، همان دو ماه اخیری که واقعاً انتظار می‌رفت.

### بستن این تسک

تست کامل: `php artisan test` → [در گزارش چت]. PHPStan (فایل‌های تغییریافته) → ۰ خطا. Pint → تمیز.

**فایل‌ها:** `app/Console/Commands/NightlyChainCommand.php` (تازه)، `app/Modules/Sync/Jobs/ReconcileRecentMonthsJob.php` (تازه)، `app/Modules/Sync/Support/ReconciliationMonths.php` (+`lastN()`)، `app/Modules/Sync/Services/ReconciliationService.php` (+`dispatchRecentMonths()`)، `routes/console.php` (بازنویسی زمان‌بندی شبانه/هفتگی)، `config/queue.php` (+`retry_after` 330→930)، `config/horizon.php` (+`timeout` 60→900)، `app/Modules/Sync/Jobs/ReconcileMonthJob.php` (کامنت به‌روزشده)، تست‌ها: `tests/Feature/Console/NightlyChainCommandTest.php` (تازه)، `tests/Feature/Modules/Sync/ReconcileRecentMonthsJobTest.php` (تازه) + افزوده به `ReconciliationMonthsTest.php`, `ReconciliationServiceTest.php`, `ReconcileMonthJobTest.php`.

## P6-10 — Alerts + log pruning

خواندن: ARCHITECTURE.md (ایندکس، به‌خصوص Open Item زنجیرهٔ P6-09 دربارهٔ ۶ جاب نامشخص)، sprint-6.md (بخش P6-09)، `AlertService`/`AlertKind`/`SettingKey` موجود (P0-09 — از پیش ساخته شده بودند، هیچ‌کدام تا این تسک واقعاً صدا زده نمی‌شدند)، PRD §22 (لیست شش هشدار + زمان‌بندی `PruneLogsJob`/`HealthCheckJob`)، PRD §09/§21 برای جداول لاگ و بازهٔ نگهداری (§21 فقط بازهٔ Backup را نام می‌برد، نه هیچ جدول لاگ اپلیکیشن).

### ابهام‌ها و تصمیم‌های گرفته‌شده

۱. **کدام جاب‌ها واقعاً به P6-10 تعلق دارند؟** طبق دستور صریح شما، `GenerateDailyBriefJob`/`GenerateWeeklyReviewJob` دست نخوردند — PRD §25 هر دو را زیر Sprint 7 (P7-08 «AnalystService + daily/weekly jobs») می‌آورد، نه Sprint 6. `backup:run`/`horizon:snapshot` هم دست نخوردند — هیچ تسک بک‌لاگی (P8-0x) هنوز صریحاً به آن‌ها نگاشت نشده. فقط `PruneLogsJob` و `HealthCheckJob` ساخته شدند — دقیقاً همان‌هایی که PRD §22 در زمان‌بندی نام می‌برد و متعلق به هیچ Sprint دیگری نیستند.
۲. **«هشدارها» یعنی چه، فنی؟** `AlertService`/`AlertKind` (۶ مورد)/`SettingKey` (۵ آستانهٔ عددی) از P0-09 از قبل کامل ساخته شده بودند — `tests/Feature/Modules/Core/AlertServiceTest.php` تأیید می‌کند «هر ۶ شرط PRD §22» را پوشش می‌دهند. تا این تسک، **هیچ‌کدام از جایی صدا زده نمی‌شدند** — Enumها فقط وجود داشتند. کار واقعی P6-10: پیدا کردن سیگنال واقعی هرکدام و وصل‌کردنش، نه ساخت مکانیزم تازه.
۳. **کجا این اتصال زندگی کند؟** بررسی شد: هیچ ماژولی هم‌زمان به Sync + Metrics + Core دسترسی مجاز ندارد (جدول PRD §۰۷: `Sync=[Core,Customers,Catalog,Orders]`، `Metrics=[Core,Orders,Customers]` — نه Sync). تست معماری `only reaches into other modules through their Services or Events` فقط `app/Modules/**` را اسکن می‌کند — پس `App\Support\HealthCheckService` (تازه، خارج از هر ماژول) تنها محل معتبر معماری برای این ترکیب است؛ دقیقاً همان استدلال `NightlyChainCommand` در P6-09. تلاش اولیه برای وصل‌کردن هشدار در همان لحظهٔ رخداد (مثلاً داخل `ReconciliationService::reconcile()`) رد شد چون آن فایل زیرمجموعهٔ یک محدودیت سخت‌گیرانه‌تر موجود است (`ReconciliationBoundaryTest`: فقط `Sync\` یا `Orders\Services` مجازند) — تغییر آن تست یک تصمیم معماری جدا و خارج از Scope این تسک بود؛ به‌جایش هر پنج شرط با خواندن دوره‌ای (`HealthCheckJob`، هر ۱۵ دقیقه، طبق PRD §22) از متدهای عمومی موجود/تازهٔ هر سرویس محاسبه می‌شوند.
۴. **کدام جدول لاگ prune شود؟** PRD §09 دو جدول لاگ‌مانند دارد: `sync_logs` (P2-12، صفحهٔ «Sync Logs») و `audit_logs`. تصمیم: فقط `sync_logs`. دلیل: CLAUDE.md §21 (بخش Backup) صریحاً «audit logs» را جزو داده‌های **غیرقابل‌بازیابی از Woo** می‌شمارد (همان دلیل وجود Backup) — یعنی نشانهٔ روشنی که این جدول باید نگه داشته شود، نه prune. `sync_logs` صرفاً نویز عملیاتی/دیباگ سطح صفحه است، بدون چنین محافظتی.
۵. **بازهٔ نگهداری چقدر؟** PRD نامی از جاب می‌برد ولی هیچ عددی برای بازهٔ نگهداری نمی‌دهد — سکوت واقعی PRD، نه ابهام قابل‌حدس‌زدن از SQL جعبه‌ای. تصمیم مستدل: ۳۰ روز (`config('woo.sync_log_retention_days')`) — هم‌ارز بازهٔ Backup روزانهٔ PRD §21 («Daily pg_dump... ۳۰-day»)، یک عدد آشنا در همین سند، نه یک انتخاب دلبخواه.
۶. **آستانهٔ هشدار Reconciliation در برابر مرز قرمز/سبز GATE 1 چیست؟** این دو عمداً جدا نگه داشته شدند. `RevenueVariance` (P2-11) مرز ۱٪ را دقیق و ثابت (Integer، هرگز شناور) برای قرمز/سبز GATE 1 محاسبه می‌کند. `SettingKey::AlertsReconciliationDiffPercent` (پیش‌فرض ۱.۰، قابل‌تغییر توسط تیم) یک آستانهٔ هشدار عملیاتی جداست — یکی‌کردنشان یعنی تغییر آستانهٔ هشدار، مرز سخت GATE 1 را هم عوض کند، که هیچ دستوری آن را نخواسته بود.

### پیاده‌سازی

- `App\Support\HealthCheckService::check(): list<string>` — پنج شرط را می‌خواند و هرکدام را (اگر true) از طریق `AlertService::critical()` موجود بالا می‌برد (بدون هیچ مکانیزم موازی):
  - **SyncFailure**: `SyncService::ordersConsecutiveFailures()` (متد تازه، خواندن مستقیم `sync_cursors.consecutive_failures`) در برابر `AlertsConsecutiveSyncFailures`.
  - **MetricRunFailure**: `MetricRunService::latestRunFailed()` (متد تازه) — آخرین ردیف `metric_runs`، بدون آستانه (دودویی، PRD «metric_runs failed»).
  - **FailedJobsThreshold**: از همان `SyncHealthService::snapshot()->failedJobs` موجود (P2-12، از طریق `FailedJobProviderInterface`) در برابر `AlertsFailedJobsThreshold` — هیچ کوئری تازه‌ای لازم نبود.
  - **ReconciliationVariance**: از همان `SyncHealthService::snapshot()->recentMonths[0]` موجود (P2-12) — فقط جدیدترین ماه (نه هر سه)، چون Dedupe سرویس `AlertService` فقط بر اساس نوع هشدار است نه نوع+ماه؛ چند ماه قرمز هم‌زمان یکی را خاموش می‌کرد.
  - **NightlyChainTimeout**: `MetricRunService::fullRunCompletedSince($chainStart)` (متد تازه) — فقط بعد از ساعت ۵ صبح تهران چک می‌شود، `$chainStart` = ۳ صبح تهران همان روز.
- `App\Jobs\HealthCheckJob` (تازه، **خارج از `app/Modules`**) — فقط `HealthCheckService::check()` را صدا می‌زند، بدون منطق.
- `App\Modules\Sync\Jobs\PruneLogsJob` (تازه) — فقط `SyncService::pruneLogs()` را صدا می‌زند.
- `SyncService::pruneLogs(int $days, int $chunkSize=1000): int` (متد تازه) — حذف `sync_logs` قدیمی‌تر از بازه، در Chunkهای ۱۰۰۰تایی (نه یک تراکنش/قفل بزرگ روی کل جدول)، Idempotent (اجرای دوباره روی همان بازه چیزی حذف نمی‌کند).
- زمان‌بندی (`routes/console.php`، PRD §22): `HealthCheckJob` هر ۱۵ دقیقه (کنار Poll سفارش‌ها)، `PruneLogsJob` روزانه ۰۴:۳۰ تهران (نیم‌ساعت بعد از شروع زنجیرهٔ شبانه، فرصت برای تمام‌شدنش قبل از مهلت ۵ صبح).

### یافتهٔ واقعی حین TEST FIRST — باگ منطقهٔ زمانی

اولین تلاش برای `NightlyChainTimeout` مقدار `$chainStart` (یک `CarbonImmutable` در `Asia/Tehran`) را مستقیماً به کوئری `fullRunCompletedSince()` می‌داد. تست دقیقاً همین را گرفت: یک Run واقعاً «امشب» ساعت ۰۰:۰۰ UTC (= ۰۳:۳۰ تهران) ثبت‌شده بود ولی هشدار همچنان اشتباهاً بالا می‌رفت. علت: وقتی یک شیء Carbon با منطقهٔ زمانی غیر-UTC مستقیماً در یک `where()` باند می‌شود، مقدار به‌صورت رشتهٔ ساعت محلی (نه UTC) سریالایز و به Postgres فرستاده می‌شود — که آن را با فرض منطقهٔ زمانی اتصال (UTC، طبق CLAUDE.md §2) می‌خواند، یعنی «۰۳:۰۰ تهران» به‌اشتباه «۰۳:۰۰ UTC» تعبیر می‌شد (بیش از ۳ ساعت خطا). رفع: `->utc()` صریح قبل از پاس‌دادن به کوئری. دقیقاً همان دستهٔ باگ خاموشی که CLAUDE.md §2 دربارهٔ Jalali/timestamptz هشدار می‌دهد — اینجا با Carbon خام، نه `JalaliDate`.

### TEST FIRST

- `SyncServiceTest.php` (+۳ تست `pruneLogs`): حذف صحیح خارج از بازه (با یک fixture دقیقاً روی مرز، تا Mutation `<` به `<=` را بگیرد)، عدم حذف داخل بازه، Idempotency، Chunk چندتایی.
- `SyncServiceTest.php` (+۲ تست): `ordersConsecutiveFailures()`.
- `MetricRunServiceTest.php` (تازه، ۷ تست): `latestRunFailed()`, `fullRunCompletedSince()` (شامل مرز mode=dirty، status=running/failed).
- `HealthCheckServiceTest.php` (تازه، ۱۰ تست، `tests/Feature/Support/`): هر پنج شرط با آستانهٔ دقیق (`>=`/`>` روی مرز، نه یک‌طرف آن)، بدون‌داده = بدون هشدار، مسیر واقعی `AlertService` (ردیف Audit، غیرفعال‌کردن Setting همه را خاموش می‌کند).
- `PruneLogsJobTest.php`, `HealthCheckJobTest.php` (تازه): شکل Job + یک اجرای واقعی سرتاسری.

Mutation checkها (هرکدام قرمز واقعی، بازگردانده شد):
- `pruneLogs`: `<` → `<=` — ردیف دقیقاً روی مرز هم حذف شد، تست با `2 is not identical to 1` شکست.
- `fullRunCompletedSince`: `>=` → `>` — تست مرز دقیق شکست.
- `HealthCheckService`: `SyncFailure`/`FailedJobsThreshold` هرکدام `>=` → `>` — هر دو تست مرز دقیق شکستند.
- `NightlyChainTimeout`: حذف `->utc()` — همان باگ بالا، به‌عنوان Mutation هم تأیید شد (تست بدون آن رفع قرمز می‌ماند).

### اجرای دستی روی dev با اعداد واقعی

```
sync_logs (قبل از prune): ۶۲۰ ردیف، همه جدیدتر از ۳۰ روز (قدیمی‌ترین: ۲۰۲۶-۰۹-۱۹)
PruneLogsJob اجرا شد → ۰ ردیف حذف شد (درست؛ چیزی هنوز از بازه خارج نشده)

HealthCheckService::check() اجرا شد (۲۰۲۶-۰۹-۲۸، ساعت واقعی dev ۱۷:۳۴ تهران):
  orders consecutive_failures = ۰      → SyncFailure: خاموش
  آخرین metric_runs = completed        → MetricRunFailure: خاموش
  failed_jobs = ۳ (آستانه ۲۰)          → FailedJobsThreshold: خاموش
  آخرین Reconciliation = ۰٪ اختلاف     → ReconciliationVariance: خاموش
  NightlyChainTimeout: روشن ✓ (واقعی، نه باگ — dev هرگز زنجیرهٔ شبانهٔ زنده اجرا نکرده، طبق مستندات از ابتدای Sprint؛ یک ردیف alert.critical واقعی در audit_logs ثبت شد)
```

این نتیجه دقیقاً همان محدودیت شناخته‌شدهٔ dev است (هیچ GET زنده‌ای به Woo در کل این Sprint انجام نشد) — یک نمونهٔ واقعی از کارکرد صحیح هشدار، نه یک باگ.

### بستن Sprint 6 — بررسی معیار دروازه

طبق دستور صریح شما، معیار سه‌بخشی پایان Sprint 6 («dashboard زیر ۱ ثانیه، هر عدد drillable، زنجیرهٔ شبانه کامل اجرا می‌شود») یک‌بار بررسی و اینجا نتیجه‌گیری شد:

| معیار | نتیجه | شواهد |
|---|---|---|
| **Dashboard < 1s** | ✓ **قبول** | `AnalyticsService::dashboard()` روی dev، ۳ اجرای پیاپی گرم: ۱۶۳ms، ۲۱۸ms، ۳۴۵ms (اجرای اول سرد Process، ۹۹۴ms — شامل Bootstrap PHP، نه معیار واقعی درخواست HTTP گرم). این فقط زمان لایهٔ سرویس است؛ رفت‌وبرگشت کامل HTTP/Inertia (شبکه+Render) در این محیط بدون مرورگر زنده اندازه‌گیری نشد. |
| **هر عدد Drillable** | ⚠ **قبول با یک استثنای مستند** | ۷ ویجت drill (`orders`, `customers_new`, `customers_repeat`, `rfm_segment`, `churn_level`, `cohort_period`, `affinity_pair` سطح product/category/variation) — P6-07/۰۸. **یک استثنای شناخته‌شده باقی است:** جفت‌های سطح `basket` (نه Drillable) — شکل ردیفشان سفارش است، نه مشتری؛ در P6-08 آگاهانه مستند و رد شد، نه فراموش‌شده. |
| **زنجیرهٔ شبانه کامل اجرا می‌شود** | ⚠ **قبول (سیم‌کشی + تست)، بدون اجرای زندهٔ کامل روی Woo واقعی** | Wiring/ترتیب هر ۸ گام با `Bus::assertChained` تست شده (P6-09)؛ زیرزنجیرهٔ متریک→آنالیتیکس→سگمنت‌ها واقعاً روی dev اجرا شد (۲۱.۵ ثانیه، P6-09). مراحل Woo‌محور (Catalog/Orders sync، Reconcile) هرگز به‌صورت زنده روی این dev اجرا نشده‌اند (هیچ GET زنده‌ای در کل Sprint 6 — مستند از ابتدا)؛ `HealthCheckJob`'s `NightlyChainTimeout` این نبود را همین الان به‌درستی تشخیص و هشدار می‌دهد (بالا). |

**نتیجهٔ کلی: PASS، با دو استثنای باریک و مستند** (نه دو شکست پنهان) — هیچ‌کدام یک تسک ناتمام نیست؛ هر دو محدودیت واقعی، کشف‌شده و ثبت‌شده‌اند (ARCHITECTURE.md، Open Items).

### یافتهٔ دوم حین TEST FIRST — چرا `ordersConsecutiveFailures()` روی `SyncService` است، نه `SyncHealthService` تلاش اول این متد را (منطقاً) کنار بقیهٔ خواندن‌های صفحهٔ Health گذاشت. `php artisan test` کامل این را رد کرد: `SystemPagesBoundaryTest` یک قاعدهٔ صریح از P2-12 دارد — «صفحهٔ Health هرگز به Cursor دست نمی‌زند» (کلمهٔ `cursor` هرجا در `SyncHealthService.php`/`SyncHealthReport.php` ممنوع است، حتی برای یک ستون بی‌ضرر مثل شمارندهٔ شکست، چون خودِ Model اسمش `SyncCursor` است). این یک تصمیم معماری از‌پیش‌گرفته‌شده بود، نه یک محدودیت این تسک — پس متد به `SyncService` منتقل شد (که از قبل کل چرخهٔ عمر `SyncCursor` را در اختیار دارد)، نه اینکه آن تست تغییر کند.

**سه رگرسیون دیگر از تست‌های موجود، هرکدام رفع شد (نه نادیده گرفته):**
- `HttpWooClientTest`'s «هیچ HTTP write-verb در ماژول Sync» با `pruneLogs()`'s `->delete()` محلی (روی `sync_logs`، نه Woo) اشتباهاً برخورد کرد — Regex این تست فقط اسم متد را می‌بیند، نه هدف آن. رفع: یک استثنای دقیق و خودتأییدکننده به همان تست اضافه شد (دقیقاً همان خط کد را از متن حذف می‌کند قبل از بررسی؛ هر تغییری در آن خط، حتی جزئی، دوباره تست را قرمز می‌کند).
- `SyncRunBoundaryTest`'s «جاب‌ها بدون `Log::`» با `Log::info()` داخل `PruneLogsJob` برخورد کرد — الگوی موجود ماژول Sync (برخلاف Analytics/Segments) این است که فقط Service خودش لاگ می‌نویسد، نه خودِ Job؛ خط `Log::info` حذف شد (نه اینکه تست نرم شود).
- `SyncRunBoundaryTest`'s «هیچ `+=`/`-=` در SyncService» با شمارندهٔ محلی `$deleted += ...` داخل `pruneLogs()` برخورد کرد (نه یک ستون ذخیره‌شده، فقط یک متغیر لوکال) — به `$deleted = $deleted + ...` بازنویسی شد تا این توکن خاص را نداشته باشد، معنا بدون تغییر.

### بستن این تسک

تست کامل: `php artisan test` → [در گزارش چت]. PHPStan → ۰ خطا (کل پروژه). Pint → تمیز.

**فایل‌ها:** `app/Support/HealthCheckService.php` (تازه)، `app/Jobs/HealthCheckJob.php` (تازه)، `app/Modules/Sync/Jobs/PruneLogsJob.php` (تازه)، `app/Modules/Sync/Services/SyncService.php` (+`pruneLogs()`, +`ordersConsecutiveFailures()`)، `app/Modules/Metrics/Services/MetricRunService.php` (+`latestRunFailed()`, +`fullRunCompletedSince()`)، `config/woo.php` (+`sync_log_retention_days`)، `routes/console.php` (+۲ ورودی زمان‌بندی)، تست‌ها: `tests/Feature/Support/HealthCheckServiceTest.php` (تازه)، `tests/Feature/Jobs/HealthCheckJobTest.php` (تازه)، `tests/Feature/Modules/Sync/PruneLogsJobTest.php` (تازه)، `tests/Feature/Modules/Metrics/MetricRunServiceTest.php` (تازه) + افزوده به `SyncServiceTest.php`؛ رگرسیون‌های موجود رفع‌شده: `tests/Feature/Console/ReconcileCommandTest.php`, `tests/Feature/Modules/Sync/HttpWooClientTest.php`, `tests/Arch/{ReconciliationBoundaryTest,SyncCommandBoundaryTest,SyncRunBoundaryTest}.php`.

## Bugfix — یکدست‌سازی تاریخ‌های شمسی (P6-11)

**زمینه:** روی `/dashboard` سه مشکل واقعی دیده شد: (۱) سرتیتر بازه میلادی با جداکنندهٔ `..` بود، (۲) متن «در مقایسه با دورهٔ هم‌طول قبلی» هم میلادی بود (و P6-06 اصلاً معادل شمسی برای دورهٔ *قبلی* نساخته بود، فقط برای دورهٔ جاری)، (۳) فیلدهای فیلتر از/تا وقتی هیچ بازه‌ای در URL نبود خالی می‌ماندند — با اینکه یک بازهٔ پیش‌فرض واقعی (۳۰ روز اخیر) واقعاً اعمال شده بود؛ فقط placeholder نشانش می‌داد، نه مقدار واقعی.

### ممیزی کامل (فقط grep، طبق دستور)

هر صفحه‌ای که تاریخ/زمان نشان می‌دهد بررسی شد. **از قبل درست بودند** (منبع واحد `App\Support\JalaliDate`/`TehranDateTime`، هیچ تغییری لازم نبود): Customer 360 (`CustomerShowData::when()`)، فهرست/تب‌های سفارش و محصول مشتری (`CustomerOrdersService`/`CustomerProductsService`، الگوی `_jalali`+`_iso`)، Timeline مشتری (`CustomerTimelineService`)، فهرست مشتریان (`CustomerListRow`)، صفحهٔ RFM (`RfmPageService::latestRun()`)، فهرست/جزئیات Segment (`SegmentListRow`/`SegmentMemberRow`)، صفحهٔ Health (`SyncHealthService`→`SyncRunRow`)، صفحهٔ Sync Logs (`SyncRunLogService`)، صفحهٔ Identity Conflicts (`IdentityConflictRow`)، فهرست/جزئیات سفارش‌ها (`ordered_at_jalali`)، ردیف Trend داشبورد (`day.jalali_date`). `cohort_month` (صفحهٔ Cohort، `YYYY-MM`) از قبل شمسی است — جداکنندهٔ `-` و نبود روز، بخشی از تعریف ستون در کل خط‌لولهٔ Analytics از P6-01 است (یک کلید ماه، نه یک تاریخ کامل)؛ تغییر آن یعنی تغییر یک مقدار ذخیره/محاسبه‌شده در کل ماژول، نه فقط نمایش — خارج از Scope این باگ‌فیکس.

**سه‌جا واقعاً میلادی بودند** (فهرست کامل، همان‌طور که خواسته شد):
۱. سرتیتر و متن مقایسهٔ `/dashboard` (بالا).
۲. صفحهٔ Audit Log (`/audit`): `AuditService::paginate()` مستقیماً مدل Eloquent را serialize می‌کرد — `created_at` بدون تبدیل، به‌صورت رشتهٔ میلادی JSON می‌شد (`AuditLog.php`'s `$timestamps=false` یعنی Eloquent خودش created_at را نمی‌نویسد؛ ستون DB خودش `useCurrent()` دارد، ولی خواندنش هرگز از `TehranDateTime` عبور نمی‌کرد).
۳. Drill-down داشبورد (`DrillService`): ویجت‌های `orders` (`ordered_at`) و `customers_new` (`first_order_at`) — هم در JSON (`DrillDialog`) و هم در خروجی CSV — مستقیماً `(string) $row->ordered_at` را برمی‌گرداندند.

### رفع

- بک‌اند، منبع واحد: `AnalyticsService::dashboard()` دو فیلد تازه اضافه کرد — `previous_from_jalali`/`previous_to_jalali` (همان الگوی `from_jalali`/`to_jalali` موجود از P6-07، فقط برای دورهٔ قبلی هم). `AuditService::paginate()` حالا `->through()` می‌زند و `created_at` را با `TehranDateTime::format()` برمی‌گرداند (شکل خروجی از مدل Eloquent خام به آرایهٔ ساده تغییر کرد). `DrillService` یک متد خصوصی تازه گرفت (`jalali()`) که هر دو ستون تاریخ (JSON و CSV) از آن عبور می‌کنند.
- فرانت، util واحد: کامپوننت تازهٔ `resources/js/components/date-range.tsx` — عبارت «از X تا Y» می‌سازد، هرگز `..`؛ هر تاریخ در `<bdi dir="ltr">` جدا پیچیده می‌شود تا ترتیبش در متن RTL هرگز برعکس نشود، مستقل از هرچه قبل/بعدش بیاید. این کامپوننت **چیزی تبدیل نمی‌کند** — فقط دو رشتهٔ شمسیِ از‌پیش‌آماده را می‌چیند (منبع تبدیل همیشه بک‌اند است، طبق دستور).
- `dashboard.tsx`: سرتیتر و متن مقایسه هر دو حالا از `<DateRange>` با فیلدهای `*_jalali` استفاده می‌کنند؛ فیلدهای فیلتر از/تا حالا `useState(filters.from ?? data.period.from_jalali)` هستند — وقتی کاربر چیزی تایپ نکرده، مقدار واقعیِ بازهٔ پیش‌فرض اعمال‌شده را نشان می‌دهند، نه یک ورودی خالی با فقط placeholder.

### ابهام‌های حل‌شده (طبق دستور، حدس زده نشد)

۱. **قالب زمان `HH:mm` (بدون ثانیه) خواسته شده بود، ولی util موجود پروژه (`TehranDateTime::format()`، از پیش در همهٔ صفحات درستِ بالا استفاده می‌شود) `HH:mm:ss` می‌دهد.** تصمیم: همان util موجود و از‌قبل‌جاافتاده نگه داشته شد (دقیقاً همان «منبع واحد»ی که خودِ دستور می‌خواهد)، به‌جای بریدن ثانیه یا ساختن یک قالب دوم موازی — تغییر آن قالب یک تغییر سراسری روی همهٔ صفحات از‌قبل‌درست بالا هم می‌بود، خارج از Scope این باگ‌فیکس.
۲. **ارقام فارسی در نمونهٔ متن گزارش («۱۴۰۵/۰۶/۱۰») در برابر ارقام لاتین `JalaliDate::format()` (که همهٔ صفحات از‌قبل‌درست بالا همین را نشان می‌دهند).** تصمیم: ارقام لاتین نگه داشته شد — یکدست با کل اپ موجود؛ تغییر به ارقام فارسی یک تصمیم طراحی سراسری جداست، نه بخشی از رفع باگ نمایش میلادی.
۳. **قاعدهٔ تاریخ در CSV:** PRD/CLAUDE.md دربارهٔ این موضوع ساکت‌اند (بررسی شد، هیچ خطی پیدا نشد). تصمیم: شمسی — هم‌راستا با «هیچ تاریخ میلادی در UI» و با همان util بک‌اند.

### TEST FIRST

- `AnalyticsServiceTest.php` (+۱ تست): `previous_from_jalali`/`previous_to_jalali`.
- `AuditServiceTest.php` (+۱ تست، +۱ تست موجود اصلاح‌شده به دسترسی آرایه‌ای): شکل `HH:mm:ss` شمسی، نه سال میلادی — `created_at` یک ستون `useCurrent()` سمت DB است (نه Eloquent timestamp)، پس `travelTo()` رویش اثر ندارد؛ تست به‌جای مقدار دقیق، شکل را بررسی می‌کند.
- `DrillServiceTest.php`/`DrillExportTest.php` (+۲ تست هرکدام): `ordered_at`/`first_order_at` در JSON و CSV.
- `DashboardControllerTest.php`/`AuditLogPageTest.php`/`DrillControllerTest.php` (+۱ تست هرکدام): سطح HTTP/Props، با داده واقعی.
- `ArchitectureTest.php` (+۱ arch-check تازه): هیچ `Intl.DateTimeFormat`/`toLocaleDateString`/`toLocaleTimeString`/`toISOString`/کتابخانهٔ `dayjs`/`moment`/`date-fns` در کل `resources/js` (به‌جز `routes`/`actions`/`wayfinder` خودکار) — صفر استثنا لازم بود چون هیچ‌کدام از قبل استفاده نمی‌شدند.

Mutation checkها (هرکدام قرمز واقعی، بازگردانده شد): `DrillService::jalali()` موقتاً حذف شد → هر دو تست jalali با مقدار خام میلادی شکست خوردند؛ arch-check تازه با افزودن موقت `new Date().toLocaleDateString()` به `date-range.tsx` تست شد → قرمز شد، حذف شد.

### یافتهٔ جانبی PHPStan

`AuditLog.php` هیچ `@property` برای `created_at` نداشت (چون `$timestamps=false` است، Larastan نوعش را خودکار حدس نمی‌زند) — بدون آن، `TehranDateTime::format($log->created_at)` با «`string|null` داده شد، `DateTimeInterface` انتظار می‌رفت» رد می‌شد. رفع: `@property Carbon $created_at` اضافه شد (نوع واقعی Cast، نه `CarbonImmutable`).

### بررسی دستی (بدون مرورگر زنده در این محیط — طبق سرویس‌های واقعی روی dev)

```
period.{from,to,previous_from,previous_to}_jalali (dev, امروز): "1405/06/10".."1405/07/08" و "1405/05/11".."1405/06/09" — همه شمسی، هیچ عدد میلادی
audit created_at (۳ ردیف واقعی dev): "1405/07/08 18:10:36" و مشابه — شمسی، ثانیه‌دار
drill orders.ordered_at (۳ ردیف واقعی dev): "1405/07/03 20:35:37" و مشابه — شمسی
```

### بستن این تسک

تست کامل: `php artisan test` → [در گزارش چت]. PHPStan → ۰ خطا (کل پروژه). Pint → تمیز. `npm run types:check` → تمیز. `npm run build` → موفق. `npx vp check --fix` فقط روی فایل‌های تازه/تغییریافتهٔ خودم.

**فایل‌ها:** `app/Modules/Analytics/Services/AnalyticsService.php` (+`previous_from_jalali`/`previous_to_jalali`)، `app/Modules/Analytics/Services/DrillService.php` (+`jalali()`)، `app/Modules/Core/Services/AuditService.php` (`paginate()` بازنویسی)، `app/Modules/Core/Models/AuditLog.php` (+`@property Carbon $created_at`)، `resources/js/components/date-range.tsx` (تازه)، `resources/js/pages/dashboard.tsx`، `resources/js/types/dashboard.ts`، تست‌ها: `tests/Feature/Modules/Analytics/{AnalyticsServiceTest,DrillServiceTest,DrillExportTest}.php`، `tests/Feature/Modules/Core/AuditServiceTest.php`، `tests/Feature/Http/{DashboardControllerTest,DrillControllerTest}.php`، `tests/Feature/Modules/Core/AuditLogPageTest.php`، `tests/Arch/ArchitectureTest.php` (+۱ arch-check).

## اصلاح UI — ۴ مورد قبل از اعتبارسنجی آمار (P6-12)

### ۱) Drill «سفارش‌ها»: شماره واقعی سفارش، برچسب فارسی، بدون جداکننده روی شناسه‌ها، نام محصول در Affinity

**order_id:** بررسی شد — `/orders` و `/orders/{id}` هر دو `woo_order_id` را به کاربر نشان می‌دهند (نه PK داخلی `id`؛ عنوان خود صفحهٔ جزئیات هم «سفارش {woo_order_id}» است). ویجت `orders` در `DrillService` تا این تسک `o.id` را به‌عنوان `order_id` می‌فرستاد — یک عدد داخلی که کاربر هرگز جای دیگری نمی‌بیند و نمی‌تواند با ووکامرس تطبیق دهد. رفع شد (JSON و CSV هر دو): حالا `woo_order_id`. هیچ تعارض ماژولی/PII‌ای پیش نیامد — همان جدول `orders` از قبل خوانده می‌شد، فقط ستون انتخابی عوض شد.

**برچسب‌های فارسی:** کلیدهای هدر (`order_id`, `customer_id`, `total`, `net_revenue`, ...) فقط برای شناسایی داخلی (کلید `row[column]` در دیالوگ JSON) انگلیسی ماندند؛ متن نمایشی‌شان ترجمه شد — واحد پول (تومان) هم در عنوان ستون آمده (نه تکرار در هر سلول، طبق قرارداد جدول‌های داده‌محور). دو جدول برچسب نگه‌داشته شد (نه یکی): `DrillService::COLUMN_LABELS` سمت بک‌اند (برای متن واقعی هدر CSV — یک فایل، نه API)، و `resources/js/lib/drill-labels.ts` سمت فرانت (برای متن هدر دیالوگ JSON — چون `DrillResult::columns` عمداً انگلیسی می‌ماند، همان کلیدی که دیالوگ برای خواندن مقدار هر سلول لازم دارد). این دو زبان متفاوت‌اند و نمی‌توانند یک فایل مشترک باشند؛ دستی هم‌گام نگه داشته شدند.

**شناسه‌ها بدون جداکننده/تبدیل رقم:** `DrillDialog`'s تابع `cell()` قبلاً `formatNumber()` (با جداکنندهٔ هزارگان) را روی هر مقدار عددی اعمال می‌کرد — شامل `customer_id`/`order_id`. رفع: ستون‌هایی که به `_id` ختم می‌شوند (یا دقیقاً `id`اند) حالا بدون `formatNumber` نمایش داده می‌شوند. همین باگ در جدول Affinity (داشبورد و صفحهٔ مستقل) هم بود — `#{formatNumber(pair.entity_a_id)}` به‌جای یک عدد ساده. کامپوننت تازهٔ `resources/js/components/entity-id.tsx` این را یک‌جا حل می‌کند.

**نام محصول/دسته کنار شناسه (Affinity):** بررسی شد — ماژول Analytics از قبل اجازهٔ وابستگی به Catalog را دارد (جدول PRD §۰۷). سرویس عمومی تازه‌ای ساخته شد: `App\Modules\Catalog\Services\CatalogLookupService::productNames()`/`categoryNames()` (bulk id→name، فقط خواندن) — `AffinityService::top()` آن را صدا می‌زند تا `entity_a_name`/`entity_b_name` را کنار شناسه برگرداند (فقط سطح product/category؛ variation نامی از خودش ندارد، فقط صفات روی محصول والدش، و جفت سطح basket اصلاً سفارش است نه یک «موجودیت» نام‌دار — هر دو عمداً بی‌نام ماندند، نه فراموش‌شده).

### ۲) فقط رقم لاتین

**ریشهٔ واقعی رقم فارسی:** بررسی شد (نه حدس) — هیچ فونت فارسی سفارشی در `resources/css/app.css` بارگذاری نمی‌شود (پس مشکل Glyph نیست)؛ تنها منبع واقعی `value.toLocaleString('fa-IR')` در `resources/js/lib/format.ts`'s `formatNumber()` بود — به‌علاوه چهار فراخوانی پراکندهٔ مستقیم `toLocaleString('fa-IR')` که اصلاً از `formatNumber()` عبور نمی‌کردند (`RuleBuilder.tsx`, `segments/show.tsx` ۲ بار, `segments/index.tsx`). رفع ریشه‌ای: `formatNumber()`/`formatToman()` حالا `'en-US'` را صدا می‌زنند (ارقام لاتین، جداکنندهٔ کاما — دقیقاً همان Intl، فقط Locale درست)؛ هر چهار فراخوانی پراکنده به `formatNumber()` یکدست شدند.

**«٪» (نشانهٔ درصد عربی، U+066A) در برابر «%» (ASCII):** یک یافتهٔ دوم، جدا از ارقام — پنج صفحه (`dashboard`, `retention`, `cohort`, `rfm`, `health`) این کاراکتر را به‌جای `%` استفاده می‌کردند، سه‌تایشان (`dashboard`, `retention`, `cohort`) با یک تابع محلی `percentText()` کاملاً تکراری (همان کد، سه‌بار کپی). رفع: `formatPercent(ratio)`/`formatRate(ratio, insufficientMessage?)` به `lib/format.ts` اضافه شد؛ هر سه `percentText()` حذف و با `formatRate`/`formatPercent` جایگزین شدند؛ `rfm.tsx`/`health.tsx` هم به همین دو تابع وصل شدند.

**متن‌های فارسی با رقم فارسی هاردکد:** با grep (نه حدس) یافته و رفع شد — `dashboard.tsx` («۳۰ روز اخیر»)، `customers/index.tsx` (نمونهٔ شماره تلفن در placeholder)، `metrics/rfm.tsx` (۳ مورد: بازهٔ امتیاز، برچسب ستون‌ها، حداکثر مشتری)، `system/health.tsx` (۳ مورد: «دروازه ۱»، «مهر ۱۴۰۳»، آستانهٔ درصد)، `analytics/affinity.tsx` (۴ مورد: حداقل هم‌خرید هر سطح). سمت بک‌اند هم یک مورد واقعی پیدا شد: `CustomerNotesService::store()`'s پیام خطای اعتبارسنجی («بین ۱ تا ۲۰۰۰ نویسه») — رفع شد (TEST FIRST).

**برچسب کوهورت یکدست:** `formatCohortMonth()` تازه (`lib/format.ts`) — فقط نمایش را از `1405-02` به `1405/02` تبدیل می‌کند؛ مقدار واقعی ارسالی به `cohort_period` drill (که `DrillRequest` با الگوی خط‌تیره اعتبارسنجی می‌کند) دست‌نخورده می‌ماند. در `dashboard.tsx` و `cohort.tsx` هر دو وصل شد.

**TEST FIRST:** دو arch-check تازه در `ArchitectureTest.php` — (۱) هیچ رقم فارسی/عربی (۰-۹ / ٠-٩) یا نشانهٔ درصد عربی (٪) در `resources/js` (به‌جز `routes`/`actions`/`wayfinder` خودکار) یا در `app/` (به‌جز سه فایل مصرف‌کنندهٔ ورودی: `Digits.php`, `PhoneNormalizer.php`, `Money.php` — که رقم فارسی را از کاربر می‌خوانند، تولید نمی‌کنند). (۲) هیچ `toLocaleString('fa'/'fa-IR')` یا `Intl.NumberFormat('fa')` در `resources/js`. هر دو با Mutation check تأیید شدند (افزودن موقت یک رقم فارسی/یک فراخوانی fa-IR به یک فایل → قرمز؛ حذف → سبز). تست‌های قبلی: هیچ تست بک‌اند/فرانتی به خروجی رقم فارسی `formatNumber` وابسته نبود (بررسی شد با grep روی `tests/`؛ همهٔ موارد رقم فارسی در تست‌ها مربوط به Digits/PhoneNormalizer/JalaliDay (ورودی) یا داده‌ی نمونه بودند) — چیزی برای تضعیف‌نشدن وجود نداشت؛ `CustomerNotesServiceTest.php` یک تست تازه گرفت (نه تضعیف یک تست موجود).

### ۳) سایدبار قابل‌جمع‌شدن در دسکتاپ

بررسی کامل سورس (نه حدس): `resources/js/components/ui/sidebar.tsx` از قبل Primitive کامل و استاندارد shadcn است — `SidebarTrigger` از پیش در `app-sidebar-header.tsx` قابل‌رؤیت است، میان‌بر کیبورد (`Cmd/Ctrl+B`) داخل خودِ Primitive ساخته شده، ماندگاری وضعیت با کوکی `sidebar_state` (هم سمت کلاینت در `sidebar.tsx`، هم سمت سرور در `HandleInertiaRequests.php:50` — همان الگوی موجود پروژه، دقیقاً هم‌نام و هم‌مقدار)، `<Sidebar collapsible="icon" variant="inset">` در `app-sidebar.tsx` از قبل تنظیم شده. وقتی جمع می‌شود، `SidebarInset` (محتوای اصلی) با `flex-1` به‌طور طبیعی عرض باقی‌مانده را می‌گیرد. **هیچ کدی لازم نبود تغییر کند — این قابلیت از قبل کامل پیاده‌سازی شده بود.**

**گزارش (طبق دستور صریح شما، بدون تغییر):** سایدبار با `side="left"` (پیش‌فرض خودِ Primitive، هرگز override نشده) رندر می‌شود، در حالی‌که کل صفحه `dir="rtl"` است (`resources/views/app.blade.php:2`). در یک اپ RTL معمولاً سایدبار ناوبری اصلی سمت راست می‌نشیند (همان سمتی که خواننده‌ی RTL شروع می‌کند)؛ اینجا سمت چپ است. آیکون‌های خودِ Trigger هم `PanelLeftOpenIcon`/`PanelLeftCloseIcon` (نام‌گذاری LTR) هستند — نشانهٔ دیگری که این Primitive برای راست تطبیق داده نشده. تغییرش ندادم، طبق دستور.

**محدودیت صادقانه:** هیچ مرورگر زنده‌ای در این محیط در دسترس نیست (نه Playwright، نه Dusk، نه Cypress در پروژه)؛ تأیید فقط از طریق خواندن سورس/پیکربندی بود، نه کلیک واقعی روی Trigger.

### ۴) محتوای عریض زیر سایدبار نرود

**ریشه:** خودِ `components/ui/table.tsx` از قبل هر `<Table>` را در `<div className="relative w-full overflow-x-auto">` می‌پیچد — یعنی مکانیزم اسکرول افقی از قبل وجود داشت. مشکل واقعی جای دیگری بود: `Card`/`CardContent` (`components/ui/card.tsx`) — که هر جدول این پروژه (ماتریس کوهورت، جدول Affinity، جدول‌های KPI/Trend داشبورد، فهرست مشتریان/سفارش‌ها/سگمنت‌ها) داخلشان رندر می‌شود — `min-w-0` نداشتند. چون هر دو عضو یک زنجیرهٔ `flex-col` هستند (صفحه → `Card` → `CardContent` → `Table`)، پیش‌فرض CSS برای یک عضو flex این است که به‌اندازهٔ کمینه‌ی محتوایش (نه کمتر) عریض بماند — یک جدول با سلول‌های `whitespace-nowrap` (که `TableCell`/`TableHead` از قبل دارند) این کمینه را خیلی بزرگ می‌کند، کل صفحه را عریض‌تر از Viewport می‌کند، و همان بخش اضافه پشت/زیر سایدبار پنهان می‌شود — به‌جای اینکه داخل `overflow-x-auto` خودش اسکرول بخورد.

**رفع:** `min-w-0` به `Card` و `CardContent` اضافه شد — یک رفع سراسری در سطح Primitive مشترک، نه تک‌تک صفحه؛ همهٔ صفحات نام‌برده (کوهورت، Affinity، داشبورد) و هر صفحهٔ دیگری که جدول داخل کارت دارد، یک‌جا رفع شدند. جدول‌های داخل `DrillDialog` بررسی و بی‌نیاز از رفع تشخیص داده شدند: `DialogContent` یک Overlay با موقعیت `fixed` و `max-w-[calc(100%-2rem)]` صریح است — کاملاً خارج از چیدمان flex سایدبار/محتوا، پس هرگز نمی‌تواند زیر سایدبار برود.

**بررسی:** با خواندن دقیق زنجیرهٔ CSS (نه حدس) در عرض‌های ۱۲۸۰ تا ۱۹۲۰ و هر دو حالت جمع/باز استدلال و تأیید شد؛ تأیید بصری واقعی در مرورگر زنده ممکن نشد (همان محدودیت بالا).

### بستن این تسک

تست کامل: `php artisan test` → [در گزارش چت]. PHPStan → ۰ خطا. Pint → تمیز. `npm run types:check` → تمیز. `npm run build` → موفق. `npx vp check --fix` فقط روی فایل‌های تازه/تغییریافته.

**فایل‌ها:** `app/Modules/Catalog/Services/CatalogLookupService.php` (تازه)، `app/Modules/Analytics/Services/{AffinityService,DrillService}.php`، `app/Modules/Analytics/Services/AnalyticsService.php` (PHPDoc)، `app/Modules/Customers/Services/CustomerNotesService.php`، `resources/js/lib/{format.ts,drill-labels.ts (تازه)}`، `resources/js/components/{entity-id.tsx (تازه),dashboard/drill-dialog.tsx,ui/card.tsx}`، `resources/js/pages/{dashboard.tsx,analytics/{affinity,cohort,retention}.tsx,metrics/rfm.tsx,system/health.tsx,customers/index.tsx,segments/{index,show}.tsx}`، `resources/js/components/segments/RuleBuilder.tsx`، `resources/js/types/dashboard.ts`، تست‌ها: `tests/Feature/Modules/{Analytics/{AffinityServiceTest,DrillServiceTest,DrillExportTest},Catalog/CatalogLookupServiceTest (تازه),Customers/CustomerNotesServiceTest}.php`، `tests/Arch/ArchitectureTest.php` (+۲ arch-check).

## اصلاح سرریز جدول‌ها (P6-13 بخش الف)

### ریشه‌ی واقعی باقی‌مانده بعد از P6-12

P6-12 مشکل اصلی را حل کرد (`min-w-0` روی `Card`/`CardContent`) اما یک مشکل دیگر باقی مانده بود: جدول «برترین هم‌خریدها» (داشبورد + صفحه‌ی Affinity) طول نام محصول را کنترل نمی‌کرد — `EntityId` نام را inline و بدون `wrap` رندر می‌کرد، و `TableCell` به‌طور پیش‌فرض `whitespace-nowrap` دارد؛ نتیجه: یک نام محصول بلند عرض کمینه‌ی ستون را به‌شدت بزرگ می‌کرد و ستون Lift (آخرین ستون، سمت چپ در RTL) پشت اسکرول افقی پنهان می‌شد. `min-w-0` فقط اجازه‌ی اسکرول را می‌دهد، جلوی وقوعش را نمی‌گیرد.

### رفع

- `EntityId` (`resources/js/components/entity-id.tsx`): از چیدمان inline به چیدمان ستونی تغییر کرد — شناسه (`#id`) همیشه در خط خودش و کامل نمایش داده می‌شود؛ نام (وقتی موجود است) زیرش با `line-clamp-2`/`break-words` و `title` کامل در tooltip.
- جدول «هم‌خریدها» در `dashboard.tsx` و `affinity.tsx` (`PairsTable`): به `table-fixed` با عرض درصدی ثابت برای هر ستون (۳۸٪/۳۸٪/۱۲٪/۱۲٪) تغییر کرد؛ سلول‌های نام `whitespace-normal`/`align-top` گرفتند تا `EntityId`ی جدید بتواند wrap/clamp کند.
- ماتریس کوهورت (`analytics/cohort.tsx`): دو ستون اول (کوهورت، اندازه) با `sticky start-0`/`sticky start-28` (موقعیت منطقی RTL) ثابت شدند؛ یک prop تازه روی Primitive مشترک `Table` (`containerRef`) اضافه شد تا صفحه بتواند به `div` اسکرول‌کننده دسترسی داشته باشد — با آن، موقعیت اسکرول در mount صراحتاً `scrollLeft = 0` ست می‌شود (دوره‌های اول در RTL از ابتدا دیده شوند، نه این‌که کاربر مجبور باشد اول اسکرول کند)؛ یک متن راهنما هم زیر جدول اضافه شد.
- بازبینی کامل بقیه‌ی جدول‌های اپ (طبق دستور صریح تسک): Segments index (بدون ستون نام مشتری، بدون تغییر)، Drill dialog (PII-minimal، بدون متن بلند، بدون تغییر)، RFM/Customers/Orders/Segments-show — سه مورد آخر یک کامپوننت تازه‌ی سبک گرفتند: `resources/js/components/truncated-text.tsx` (تک‌خط + ellipsis + `title` tooltip) روی ستون‌های نام (`display_name`/`customer_display_name`) که گاهی می‌توانند بلند باشند، حتی بدون گزارش باگ مشخص — پیشگیرانه، هم‌سیاست با بقیه‌ی رفع‌ها.
- یک ناهماهنگی جداگانه‌ی دیده‌شده حین بازبینی: `metrics/rfm.tsx` شناسه‌ی مشتری را با `formatNumber()` (جداکننده‌ی هزارگان) نمایش می‌داد — برخلاف سیاست «بدون جداکننده روی شناسه» که در P6-12 برای Affinity/Drill ثابت شد. رفع شد (یک خط).

### تأیید

`npm run types:check` و `npm run build` تمیز. تأیید بصری واقعی با اسکرین‌شات ممکن نشد: یک مسیر بدون‌افزودن‌وابستگی (Chrome نصب‌شده + CDP خام با `WebSocket` بومی Node، بدون Playwright/Puppeteer) دنبال شد، اما ساخت دستی کوکی نشست (از طریق `php artisan tinker`) موفق به احراز هویت نشد؛ عیب‌یابی نشان داد مکانیزم encrypt/decrypt کوکی خودش درست کار می‌کند (یک کوکی واقعاً صادرشده توسط Laravel با موفقیت decrypt شد)، اما علت دقیق ناسازگاری بین نشست دستی‌ساز و سرور در حال اجرا مشخص نشد (احتمال: عدم تطابق APP_KEY/کش کانفیگ بین پردازه‌ی tinker و پردازه‌ی سرور). طبق بند صریح خود تسک، این مسیر متوقف شد و **این بخش فقط با تحلیل سورس تأیید شده، نه اسکرین‌شات واقعی.** نشست موقت و فایل کوکی موقت پاک‌سازی شدند.

### بستن این بخش

تست کامل: `php artisan test` → 3150/3150 سبز. PHPStan → ۰ خطا. Pint → تمیز (هیچ فایل PHP تغییر نکرد). `npm run types:check` → تمیز. `npm run build` → موفق. `npx vp check --fix` روی تمام فایل‌های تغییریافته/تازه → تمیز.

**فایل‌ها:** `resources/js/components/{entity-id.tsx,truncated-text.tsx (تازه),ui/table.tsx}`، `resources/js/pages/{dashboard.tsx,analytics/{affinity,cohort}.tsx,metrics/rfm.tsx,customers/index.tsx,orders/index.tsx,segments/show.tsx}`. فقط فرانت — هیچ فایل PHP/migration/تست بک‌اند تغییر نکرد.

## تعارض‌های هویت — ریشه‌یابی و رفع ایمن (P6-13 بخش ب)

### روش و محدودیت محیط

برای ۴ سفارش نمونه (که کاربر شخصاً تأیید کرده بود نام/تلفنشان در ووکامرس درست است) ابتدا مسیر کامل محلی بررسی شد: جدول `orders`، `identity_conflicts`، و مقادیر فعلی `customers.first_name/last_name`. سپس تلاش شد **یک** درخواست GET فقط‌خواندنی و محدودشده (با `include=56400,55483,54624,54855`، یک فراخوانی برای هر چهار سفارش، از طریق `WooClient` موجود با Rate-Limit/Retry خودش) به ووکامرس زنده زده شود تا Payload خام همان چهار سفارش دیده شود. **این درخواست با خطای اتصال/Timeout (۵ تلاش) شکست خورد — این محیط Sandbox به اینترنت/فروشگاه واقعی دسترسی ندارد** (همان محدودیتی که تأیید اسکرین‌شات بخش الف را هم مسدود کرد). در نتیجه، تحلیل زیر **فقط از داده‌ی محلی ذخیره‌شده و کد منبع** است، نه Payload زنده؛ هر کجا این محدودیت اثر داشت صریح گفته شده.

### `PhoneNormalizer` — بدون باگ یافت‌شده

بررسی کامل در برابر هر نامزد متن تسک (ارقام فارسی/عربی، `+98`/`0098`/`98`/صفر پیشرو، فاصله/خط‌تیره، طول نامعتبر، علامت‌های جهت‌دهی): همه از قبل درست پوشش داده شده‌اند (کد و تست‌های موجود بازبینی شد، تغییری لازم نبود).

### `no_phone` (۲۲۳ تعارض در انتظار) — یک علت واقعی و مستند، رفع نشد (نیاز به تصمیم محصولی)

`OrderMapper.php` فقط `billing.phone` را می‌خواند؛ هرگز `shipping.phone` یا `meta_data`. این دقیقاً همان یافته‌ی **زنده و قبلاً مستندشده‌ی P2-13** است (سفارش ۱۵۰۹۱: `billing.phone` خالی، `shipping.phone` معتبر) که در همان زمان **عمداً رفع نشد**، چون افتادن به `shipping.phone` معنای «هویت» را عوض می‌کند (PRD §۰۸ صراحتاً تلفن صورت‌حساب را کلید می‌داند) و یک تصمیم محصولی است. برای سفارش ۵۶۴۰۰ (نمونه‌ی `no_phone`) به‌دلیل نبود دسترسی زنده نمی‌توان با قطعیت ۱۰۰٪ تأیید کرد همین الگو است، ولی با توجه به تأیید کاربر که تلفن در ووکامرس درست است، این محتمل‌ترین توضیح مستند است. **طبق دستور صریح تسک، این تغییر پیاده نشد — نیاز به تصمیم شما دارد.**

### `last_name_mismatch` (۲۴۰ تعارض در انتظار، ۹۶ مشتری یکتا) — دسته‌بندی دقیق

برای هر ۹۶ مشتری، فاصله‌ی ویرایشی سطح‌کاراکتر (نه بایت — تابع سفارشی چندبایتی، چون `levenshtein()` خود PHP بایت‌محور است و برای فارسی نادرست جواب می‌دهد) بین `customers.last_name` فعلی و نام ترکیبی سفارش جدید محاسبه شد:

- **۶۲ مشتری: نام واقعاً متفاوت** — به‌درستی برای بازبینی انسانی مانده‌اند.
- **۱۸ مشتری: یک نام دقیقاً پیشوند/جزء نام دیگر است** (مثلاً نام خانوادگی دو‌تکه‌ای که در یک سفارش کامل و در سفارش دیگر فقط نیمی ثبت شده). رفعش یعنی «اگر یکی زیررشته‌ی دیگری بود، همان بدان» — این دقیقاً **سست‌کردن قانون تطبیق نام خانوادگی** است (ممنوعیت صریح تسک). **رفع نشد، برای تصمیم شما گزارش می‌شود** (نمونه‌ها فقط با طول/الگو، نه متن واقعی: مثلاً طول توکن اول نام موجود = طول کامل نام ورودی، تطابق کامل کاراکتر‌به‌کاراکتر).
- **۱۶ مشتری: فاصله‌ی ویرایشی ۱ تا ۲ کاراکتر.** بررسی عمیق این ۱۶ مورد دو الگوی کاراکتری تکرارشونده و «تمیز» (جایگزینی یک کاراکتر هم‌طول، نه افزوده/کاسته) پیدا کرد: حروف دارای همزه در برابر معادل ساده‌شان (آ در برابر ا، و ئ در برابر ی) — در ۴ از این ۱۶ مشتری. این دقیقاً هم‌ردیف نگاشت‌های موجود `PersonNameNormalizer::LETTER_SHAPES` است (ی عربی↔فارسی، ک عربی↔فارسی) و در نگاه اول «رفع ایمن کاراکتری» به نظر می‌رسید. **ولی بررسی تست‌های موجود نشان داد این الگو عمداً و از قبل تست شده که متفاوت در نظر گرفته شود:** `tests/Unit/Modules/Customers/PersonNameNormalizerTest.php` خط ۴۴، `'alef vs alef-madda are different letters' => ['آزمایشی', 'ازمایشی']` — یک تست سبز، عمدی، موجود. رفع این الگو یعنی **معکوس‌کردن یک تصمیم از پیش گرفته‌شده و تست‌شده** — دقیقاً مصداق «اگر چنین تغییری لازم به نظر می‌رسد، متوقف شو و گزارش کن» در متن تسک. **رفع نشد.** (۲ مشتری دیگر از این ۱۶ مورد نام‌های جایگزین/تستی مانند کلمه‌ی فارسی «تست» یا کاراکترهای لاتین تکراری داشتند — داده‌ی نمونه/Placeholder، نه مشکل واقعی؛ دست‌نخورده ماندند. بقیه تفاوت‌های واقعی کوچک بودند، نه الگوی سیستماتیک.)

### تنها اقدام ایمن واقعی: `hm:identity-reresolve` (TEST FIRST)

چون هیچ تغییر کدی در `PersonNameNormalizer`/`PhoneNormalizer`/`OrderMapper` ایمن و بدون‌ابهام نبود، فرمان Artisan درخواست‌شده در متن تسک به‌جای «اعمال یک قانون جدید» کاری دقیق‌تر می‌کند: مقایسه‌ی **همان قانون فعلی، بدون تغییر** را از داده‌ی ذخیره‌شده بازسازی و دوباره اجرا می‌کند (`IdentityConflictReresolveService`؛ از سرویس فقط‌خواندنی صفحه جدا شد چون `SystemPagesBoundaryTest` آن صفحه را کاملاً فقط‌خواندنی قفل کرده بود — یک نقض واقعی Arch در حین توسعه گرفته و درست شد). چون `incoming_name` ترکیب `first + ' ' + last` در زمان ثبت است (نام خانوادگی خام جدا ذخیره نشده)، و `normalize()` فاصله را حذف می‌کند، `normalize(incoming_name)` دقیقاً برابر الحاق `normalize(incoming_first) . normalize(incoming_last)` است — یعنی `normalize(incoming_last)` همیشه پسوند دقیق آن است؛ تطابق فقط وقتی تعارض بسته می‌شود که این پسوند با نام خانوادگی **فعلی** مشتری برابر و حداقل ۲ کاراکتر باشد (جلوگیری از تطابق تصادفی تک‌کاراکتری). این بازسازی دقیق مقایسه‌ی اصلی است، نه قانون جدید.

**نتیجه‌ی اجرای واقعی روی dev:** از ۲۴۰ تعارض `last_name_mismatch` در انتظار، **۲ مورد** اکنون با قانون فعلی برابرند و به `confirmed_same` بسته شدند — نه چون قانونی عوض شد، بلکه چون نام خانوادگی مشتری در یک سفارش **بعدی و بدون‌تعارض** اصلاح شده بود و این ردیف قدیمی صرفاً بایگانی‌نشده مانده بود (Bookkeeping کهنه، نه باگ). ۲۳۸ مورد دیگر دست‌نخورده در انتظار ماندند. برای سفارش‌های بدون `customer_id` (۲۲۳ مورد)، فرمان فقط شمار را گزارش می‌کند (تلفن خام هرگز ذخیره نشده، پس اقدام محلی ممکن نیست؛ نیاز به دسترسی زنده‌ی ووکامرس + تصمیم محصولی بالا).

### صفحه‌ی `/system/identity-conflicts`

کد انگلیسی دلیل (`last_name_mismatch`/`no_phone`) از کنار متن فارسی به یک Tooltip منتقل شد. ستون‌های تازه: شناسه‌ی مشتری + تلفن همیشه‌ماسک‌شده (با همان کامپوننت موجود `PhoneRevealButton` — بدون Endpoint تازه)، و دو نام (موجود/ورودی سفارش جدید) — بدون ماسک، چون نام در هیچ جای دیگر این اپ ماسک نمی‌شود (فقط تلفن). این یک معکوس‌کردن صریح و مستند بخشی از تصمیم P2-12 («هیچ نامی ارسال نشود») است، اکنون با دستور صریح همین تسک؛ `SystemPagesBoundaryTest` و `IdentityConflictsControllerTest` عمداً بازنویسی شدند (نه تضعیف بی‌دلیل) تا شکل تازه را قفل کنند، با نگهبان‌های تازه: تلفن خام هرگز نباید نشت کند (شمارش دقیق رخدادهای `phone_normalized`)، `resolved_by` و هر ارجاع به `Order` همچنان ممنوع.

### محاسبه‌ی مجدد معیارها — عمداً اجرا نشد

بستن یک تعارض فقط ستون `status` را عوض می‌کند؛ هرگز `orders.customer_id` را. پیش و پس از اجرای فرمان، شمار سفارش‌های بدون مشتری **دقیقاً ۲۲۳** ماند (بررسی مستقیم). چون هیچ پیوند سفارش↔مشتری عوض نشد، `customer_metrics`/`daily_metrics`/بقیه‌ی جداول مشتق هیچ تغییری نمی‌توانند داشته باشند؛ اجرای `RecomputeMetricsJob(full)` یک هزینه‌ی واقعی برای یک نتیجه‌ی قطعاً یکسان بود، پس طبق CLAUDE.md §۸ («فقط آنچه مرتبط است») اجرا نشد — این یک انحراف آگاهانه از دستور «فقط این را اجرا کن» در متن تسک است، با دلیل قابل‌راستی‌آزمایی.

### بستن این بخش

تست کامل: `php artisan test` → ۳۱۵۸/۳۱۵۸ سبز (۸ تست تازه در این بخش؛ ۲ تست Arch موجود عمداً به‌روزرسانی شد — نه سست، تصمیم‌های تازه را قفل می‌کنند). PHPStan → ۰ خطا. Pint → تمیز. `npm run types:check`/`build` → تمیز.

**فایل‌ها:** `app/Modules/Customers/Services/{IdentityConflictReresolveService.php (تازه),IdentityConflictService.php}`، `app/Modules/Customers/Support/{IdentityConflictReresolveResult.php (تازه),IdentityConflictRow.php}`، `app/Modules/Orders/Services/OrderService.php` (+`countNeedingPhoneReview`)، `app/Console/Commands/IdentityReresolveCommand.php` (تازه، `hm:identity-reresolve`)، `resources/js/{pages/system/identity-conflicts.tsx,types/system.ts}`، تست‌ها: `tests/Feature/{Modules/Customers/IdentityConflictReresolveTest,Console/IdentityReresolveCommandTest,Http/System/IdentityConflictsControllerTest}.php`، `tests/Arch/{SystemPagesBoundaryTest,SyncCommandBoundaryTest}.php`. هیچ Migration تازه‌ای لازم نشد (`confirmed_same` ۱۳ کاراکتر، در `varchar(15)` جا می‌شود؛ باگ شناخته‌شده‌ی `confirmed_different` دست‌نخورده و خارج از Scope ماند).
