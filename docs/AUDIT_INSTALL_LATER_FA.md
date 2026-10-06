# فاز دهم - راهنمای نصب آینده INTL Audit

این بسته بعد از بررسی گزینه‌های `spatie/laravel-activitylog` و `owen-it/laravel-auditing` انتخاب شده است. چون علاوه بر تغییرات مدل، رویدادهای کسب‌وکار را ثبت می‌کنیم، از **Spatie Activitylog v5** استفاده می‌کنیم و موتور Audit تازه‌ای نمی‌سازیم.

## قبل از اعمال

- فعلاً روی NCR یا سرورهای اصلی **هیچ** دستور Migration اجرا نکنید.
- فقط پچ‌های قبلی نصب‌نشده را به ترتیب قبلی اعمال و آزمایش کنید. این پچ به دو پکیج موجود `intl/contracts` و `intl/authorization-laravel` وابسته است؛ تغییر اجباری در پکیج‌های Billing یا Service Requests ندارد.
- این پچ فقط فایل‌های جدید اضافه می‌کند و نباید پچ‌های قبلی را بازنویسی کند.

## نصب بعدی در Sandbox

در ریشه `intl-platform`:

```bash
git apply --check intl-audit-laravel.patch
git apply intl-audit-laravel.patch
```

در `apps/sandbox` (فرض: Path repository قبلاً تنظیم شده):

```bash
composer config repositories.intl-local path '../../packages/*'
composer require 'intl/contracts:dev-main' 'intl/authorization-laravel:dev-main' \
  'intl/audit-laravel:dev-main' -W
```

اگر قبلاً `spatie/laravel-activitylog` را نصب یا Migration آن را منتشر کرده‌اید، **Migration تکراری ایجاد نکنید**. اگر منتشر نشده است:

```bash
php artisan vendor:publish --provider='Spatie\Activitylog\ActivitylogServiceProvider' --tag='activitylog-migrations'
```

در صورت استفاده از UUID/ULID برای شناسه‌های کاربر یا مدل‌ها، **پیش از migrate** ستون‌های `causer_id` و `subject_id` در Migration منتشرشده را با نوع شناسه‌های واقعی هماهنگ کنید. تنظیمات پیش‌فرض Spatie برای شناسه‌های عددی طراحی شده است.

**فقط در Sandbox و روی دیتابیس تست یا دیتابیس مستقل آزمایشی:**

```bash
php artisan migrate:status
php artisan test --filter=IntlAuditTest
php artisan test
```

تست‌های قرارداد مستقل را از ریشه:

```bash
./vendor/bin/phpunit --bootstrap vendor/autoload.php packages/contracts/tests/AuditEventTest.php
```

## قرارداد و نحوه اتصال

- در `intl/contracts` کلاس `Intl\Contracts\Audit\AuditEvent` و قرارداد `AuditRecorderInterface` اضافه می‌شود.
- `intl/audit-laravel` تنها یک Adapter روی Spatie Activitylog است و به `PrincipalProvider` موجود متصل می‌شود. مانند بقیه پکیج‌ها، پیاده‌سازی PrincipalProvider پیش‌فرض ندارد.
- هر مایکروسرویس باید رویدادهای مجازش را در `config/intl-audit.php` تعریف کند. برای انتشار فایل: `php artisan vendor:publish --tag=intl-audit-config`.
- مثال: پس از اجازه واقعی برای فعال‌سازی خدمت، `AuditEvent('catalog.service.activated','service','opaque-id',['result'=>'success'])` را با `app(AuditRecorderInterface::class)->record(...)` ثبت کنید.
- گزارش تغییر وضعیت موجود در Workflow همچنان **منبع اصلی تاریخچه وضعیت** باقی می‌ماند. Audit فقط رویدادهای امنیتی و مدیریتی منتخب را ثبت می‌کند.
- داده‌های مشتری، مدارک، پاسخ API مالی، Token و تمام فیلدهای آزاد به Audit ارسال نشوند. API این پکیج فقط کلیدهای مجاز و مقادیر کوتاه را می‌پذیرد.

## موارد امنیتی و محدودیت‌ها

1. فقط همان DB محلی هر سازمان؛ نه سرویس مرکزی یا ارتباط با دیگر سازمان‌ها.
2. احراز هویت از Guard واقعی + PrincipalProvider قابل اعتماد. ادمین مایکروسرویس هیچ اجازه پنهان یا پیش‌فرضی برای دیدن Audit ندارد؛ هر رابط نمایش آینده باید صریحاً با `audit.view` و ScopeChecker محافظت شود. این نسخه **مسیر نمایش عمومی ندارد**.
3. Spatie به‌صورت خودکار تمام عملیات SQL یا تغییرات Query Builder را ثبت نمی‌کند. صرفاً عملیات منتخب را از طریق `AuditRecorderInterface` ثبت کنید؛ برای مدل‌های حساس، `LogsActivity` فقط با فهرست سفید صفات مجاز.
4. برای تغییرات موفق محلی، ثبت Audit باید هم‌زمان و روی **همان کانکشن دیتابیس** در همان تراکنش انجام شود. Buffering / Queue Spatie را برای این نوع Audit فعال نکنید.
5. عملیات ناموفق/ردشده که تراکنش اصلی Rollback می‌شود باید پس از Rollback جداگانه ثبت شود، نه در تراکنشی که برگشت خواهد خورد. خطاهای Anonymous و پردازش‌های Service/Job فعلاً حل نشده‌اند و نباید به جای آن‌ها کاربران جعلی ساخت.
6. نتیجه پرداخت خارجی با لاگ محلی اتمی نمی‌شود؛ قابلیت Idempotency و وضعیت نامشخص متعلق به Billing است. در Audit فقط شناسه عملیاتی غیرحساس و نتیجه قطعی یا نامشخص ثبت شود.
7. جدول معمولی Activitylog **تغییرناپذیری تضمین‌شده یا WORM نیست**. در صورت الزام حقوقی باید مجوز DB، پشتیبان امن، سیاست نگهداری و خروجی قابل راستی‌آزمایی مستقل طراحی شود.
8. دستور پاک‌سازی `activitylog:clean` را بدون تصمیم رسمی مدت نگهداری اجرا یا زمان‌بندی نکنید.

## معیار پذیرش هنگام نصب نهایی

- تست‌های مستقل قرارداد، تست‌های Audit و تست‌های کامل Sandbox همگی موفق باشند.
- سناریوهای نقش/Guard جعلی یا بدون اتصال معتبر باید Fail Closed باشند.
- ثبت Audit داخل تراکنش محلی در صورت Rollback حذف شود.
- بدنه فرم، مشخصات مشتری، اعتبار کیف پول، Token و داده‌های مدارک در Activitylog وجود نداشته باشند.
- Migrationها پیش از اجرای واقعی، از نظر UUID/ULID، بازه نگهداری و دسترسی گزارش‌ها بررسی شوند.
