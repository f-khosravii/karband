# نصب INTL Service Catalog — مرحله پنجم

دو پکیج مستقل از استقرار: `intl/service-catalog` (هسته PHP) و `intl/service-catalog-laravel` (اتصال محلی به دیتابیس Laravel و Authorization/Workflow موجود).

**محدوده:** خدمت، تنظیمات غیرمحرمانه JSON، فعال/غیرفعال/بایگانی، اتصال به Workflow انتشار یافته. **بدون دسته‌بندی، Service Group، نسخه‌بندی مستقل خدمت، دیتابیس مرکزی، API یا پنل.** Revision عدد هم‌زمانی است نه نسخه تاریخچه خدمت.

## ۱. اعمال پچ

در Git Bash و از ریشه `intl-platform`:

```bash
git apply --check intl-service-catalog.patch
git apply intl-service-catalog.patch
```

اگر قبلاً ZIP را استخراج کرده‌ای پچ را دوباره اعمال نکن.

## ۲. نصب پکیج‌ها

```bash
cd ~/Desktop/intl-platform
composer require 'intl/service-catalog:dev-main' -W
cd apps/sandbox
composer require 'intl/service-catalog-laravel:dev-main' -W
php artisan package:discover
```

مانند پکیج‌های قبلی، ریشه و Sandbox باید repository نوع path برای `packages/*` داشته باشند (Sandbox: `../../packages/*`). اگر Sandbox آن را ندارد ابتدا اجرا کن:

```bash
composer config repositories.intl-local path '../../packages/*'
```

## ۳. تست بدون تغییر دیتابیس عملیاتی

Sandbox را با دیتابیس تست SQLite موقت `:memory:` و Migration منتشرشده قبلی Spatie اجرا کن. Migration کاتالوگ به‌صورت خودکار از داخل پکیج بارگذاری می‌شود.

```bash
# ریشه پروژه
cd ~/Desktop/intl-platform
./vendor/bin/phpunit -c packages/service-catalog/phpunit.xml
./vendor/bin/phpunit

# Sandbox
cd apps/sandbox
php artisan migrate:status
php artisan test --filter=IntlServiceCatalogTest
php artisan test
```

برای گنجاندن ۱۷ تست هسته در اجرای عمومی ریشه، در `phpunit.xml` اصلی زیر `testsuites` اضافه کن:

```xml
<testsuite name="ServiceCatalogCore">
    <directory>packages/service-catalog/tests</directory>
</testsuite>
```

**توجه:** `php artisan migrate:status` فقط وضعیت را نشان می‌دهد؛ اجرای تست از `RefreshDatabase` استفاده می‌کند. به دیتابیس اصلی NCR، بیمه یا هر محیط عملیاتی دست نزن.

## ۴. نحوه استفاده در هر مایکروسرویس

۱. از طریق Spatie مجوزها را بساز و به نقش‌ها اختصاص بده. نام‌های مجوز در README پکیج Laravel آمده‌اند.
۲. همان `PrincipalProvider` و `ScopeChecker` متصل به Auth/SSO واقعی را برای Authorization ثبت کن. `ScopeChecker` مجاز بودن هر نمایندگی برای هر خدمت و مالکیت درخواست‌های نمایندگی را بررسی می‌کند. ادمین سازمان دولتی فقط سرویس سازمان خودش را دارد.
۳. WorkflowDefinition مربوط به خدمت باید قبلاً در دیتابیس **همان مایکروسرویس** Publish شده باشد.
۴. با `ServiceCatalog::create(new ServiceDraft(...))` خدمت را بساز. با Revision همان خدمت آن را فعال/ویرایش کن. فقط نسخه *منتشرشده* Workflow را Bind کن.
۵. برای شروع درخواست جدید فقط `CatalogWorkflowStarter::start($serviceCode, $resourceType, $resourceId)` را از لایه امن اپلیکیشن فراخوانی کن. قبل از آن، منبع درخواست/مالکیت نمایندگی باید در لایه میزبان ثبت و قابل تأیید در `ScopeChecker` باشد.

**توجه عملیاتی:** Starter، فعال‌بودن خدمت و مجوز استفاده را در تراکنش آغاز Workflow بررسی می‌کند؛ لایه میزبان هنوز مسئول مجوز ایجاد خود منبع و هر داده اختصاصی خدمت است. دو پکیج باید به یک اتصال پایگاه داده *محلی* اشاره کنند.

## ۵. راه رفع خطا

- `SQLSTATE: no such table: roles`: Migration منتشرشده Spatie را در Sandbox بررسی کن؛ Migration را در NCR اجرا نکن.
- `Unpublished workflow`: ابتدا تعریف Workflow را Publish کن و سپس Service Binding را تنظیم کن.
- `Missing PrincipalProvider/ScopeChecker`: اتصال Authorization در میزبان وجود ندارد؛ رفتار صحیح Fail Closed است.
- `Catalog and Workflow must use the same ...`: مقدار `intl-service-catalog.connection` و `intl-workflow.connection` را در همین میکروسرویس یکسان کن.
- `StaleService`: Service را مجدداً بخوان و با Revision به‌روز تکرار کن.
