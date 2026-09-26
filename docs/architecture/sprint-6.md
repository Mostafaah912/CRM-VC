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
