# راهنمای نصب آتی — INTL Service Requests (Phase 9)

**فعلاً هیچ دستوری اجرا نکنید.** این بسته برای افزودن در مرحله نصب نهایی نگه داشته می‌شود و اجرای تست Laravel آن در این محیط انجام نشده است.

## محدوده

- `intl/service-requests`: مدل مستقل PHP برای ایجاد درخواست و شناسه مشتری نمایندگی؛ مشتری، User سیستم نیست.
- `intl/service-requests-laravel`: ایجاد اتمیک درخواست در دیتابیس **محلی همان مایکروسرویس**، رزرو نسخه فرم و مدارک و Workflow، شناسه تکرارناپذیر درخواست، کنترل دسترسی و واگذاری سایر عملیات به پکیج‌های موجود.
- هیچ وضعیت موازی، کیف پول، سرور مرکزی، دسته‌بندی خدمات یا اطلاعات شخصی مشتری به این بسته افزوده نشده است.
- هر درخواست فقط به نسخه تعریف‌های لحظه شروع خود متصل می‌شود. Workflow تنها مرجع وضعیت جاری و تاریخچه وضعیت است.

## پیش‌نیاز فاز نهایی

1. پچ‌های Contracts، Workflow و Laravel Adapter، Authorization + Spatie، Service Catalog، Dynamic Forms، Documents و **Billing** را ابتدا به ترتیب مستندات خودشان اضافه و تست کنید.
2. پایگاه داده تست محلی SQLite را تنظیم کنید. `php artisan test` فقط روی Sandbox؛ هرگز روی NCR یا دیتابیس عملیاتی اجرا نشود.
3. اتصال‌های امنیتی `PrincipalProvider`، `ScopeChecker`، `WorkflowActorResolver`، `WorkflowAuthorizationBridge`، `RecipientVerifier`، `ReplacementPolicy` و `FileSafetyScanner` باید توسط هر مایکروسرویس تأمین شوند. هیچ پیاده‌سازی پیش‌فرض برای RecipientVerifier یا اسکنر واقعی ارائه نشده است.
4. نمونه `ServiceRequestResourceVerifier` تنها برای `intl.service_request` وجود درخواست و کد خدمت را بررسی می‌کند؛ مجوز/مالکیت در Authorizer و ScopeChecker بررسی می‌شوند. اگر موجودیت‌های دیگری به Documents وصل می‌شوند، میزبان باید ResourceVerifier اختصاصیِ امن خودش را ثبت کند.
5. تمام `intl-service-requests.connection`، `intl-service-catalog.connection`، `intl-workflow.connection`، `intl-dynamic-forms.connection` و `intl-documents.connection` باید به **یک Connection واقعی** دیتابیس محلی اشاره کنند. Billing می‌تواند پیکربندی محلی متفاوتی داشته باشد و پرداخت هرگز در تراکنش ایجاد درخواست انجام نمی‌شود.

## هنگام نصب آینده

از ریشه `intl-platform`:

```bash
# فقط پس از نصب پچ‌های قبلی
# اول از یک شاخه/نسخه تمیز بکاپ بگیرید و وضعیت Git را بررسی کنید.
git apply --check intl-service-requests.patch
git apply intl-service-requests.patch
composer require 'intl/service-requests:dev-main' -W
./vendor/bin/phpunit -c packages/service-requests/phpunit.xml
```

سپس Sandbox:

```bash
cd apps/sandbox
composer config repositories.intl-local path '../../packages/*'
composer require \
  'intl/service-requests:dev-main' \
  'intl/service-requests-laravel:dev-main' -W
# فقط روی دیتابیس تست Sandbox، نه NCR
php artisan test --filter=IntlServiceRequestsTest
php artisan test
```

اگر Composer پکیج توسعه‌ای وابسته را به‌خاطر `minimum-stability` رد کرد، ابتدا پیش‌نیازهای مرتبط را صریحاً با `:dev-main` در همان دستور `composer require` وارد کنید؛ `minimum-stability` کل پروژه را به dev تغییر ندهید.

## قراردادهای میزبان و مرزها

- `RecipientVerifier::belongsToAgency()` باید رکورد مشتری را از **داده معتبر محلی** خوانده و تعلق آن به نمایندگی را بررسی کند. HTTP نباید `agency_id` یا `actor_id` را تعیین کند.
- Spatie: نقش agency به `requests.create`, `requests.view` و مجوزهای موردنیاز پکیج‌های وابسته نیاز دارد؛ ادمین سازمان به مشاهده تمام درخواست‌های **سرویس محلی خودش** فقط در صورت `requests.view` نیاز دارد. platform_admin هم تنها مجوزهای صریح دریافت می‌کند. ScopeChecker برای منابع واقعی اجباری است.
- پیش از Submit، میزبان باید Workflow Guard اختصاصی خدمت را تعریف کند تا تکمیل فرم، مدارک و سایر قواعد مربوط را از منابع **معتبر و نسخه pinشده** بررسی کند؛ این پکیج شرایط اختصاصی یک سازمان را حدس نمی‌زند.
- تغییر پاسخ‌ها/مدارک علاوه بر مجوزها باید براساس وضعیت واقعی Workflow و سیاست میزبان محدود شود. `ReplacementPolicy` برای جایگزینی فایل اجباری است.
- `createPaymentIntent()` فقط Intent محلی ایجاد می‌کند؛ پیاده‌سازی `TrustedQuoteProvider`, `CentralPaymentGateway`, استعلام قطعی و `reconcile()` در فاز اتصال آینده الزامی است. هرگز Timeout را شکست قطعی فرض نکنید و بدون استعلام دوباره برداشت نفرستید.
- انتشار پچ و تست Sandbox معادل آماده بودن برای Production نیست. اجرای عملیاتی تنها پس از تعریف API واقعی پرداخت، بررسی قفل/تراکنش روی DB مقصد، تست‌های دسترسی، migration plan و ممیزی امکان‌پذیر است.
