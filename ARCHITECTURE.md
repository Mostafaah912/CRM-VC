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

<!-- نتایج اسکریپت راستی‌آزمایی ووکامرس اینجا ثبت می‌شود. هنوز اجرا نشده. -->