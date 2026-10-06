# فاز ۱۳ — INTL Service Recipients (نصب در آینده)

**وضعیت:** بسته طراحی و کد اولیه؛ **تأیید عملیاتی یا یکپارچگی واقعی نشده است.** طبق تصمیم پروژه، اکنون چیزی نصب نکنید.

## ۱. محتویات

- `intl/service-recipients`: مدل‌ها و قراردادهای مستقل از Laravel (`RecipientKind`, `RecipientRules`, `Identity`).
- `intl/service-recipients-laravel`: ذخیره‌سازی محلی، deduplication مبتنی بر HMAC در محدوده نمایندگی، رمزگذاری داده، ویرایش با revision، درخواست/تصمیم تغییر شناسه، آرشیو/بازیابی، نماینده ذخیره‌شده اشخاص حقوقی.
- `apps/sandbox/tests/Feature/IntlServiceRecipientsTest.php`: نمونه تست Laravel با Policy/Bridge آزمایشی؛ در محیط کاربر هنوز اجرا نشده است.
- پچ هیچ فایل موجودی را بازنویسی نمی‌کند؛ تغییرات لازم در Service Requests برای اتصال **بسته دیگری در فاز ۱۵** خواهند بود.

## ۲. مرزها و تصمیم‌های قطعی

- هر سازمان استقرار/دیتابیس مستقل خودش را دارد؛ پروفایل مشتری فقط برای همان نمایندگی معتبر است.
- پشتیبانی از حقیقی و حقوقی؛ نوع/قاعده شناسه و اعتبارسنجی پروفایل را میزبان تعیین می‌کند.
- نمایندگی ویرایش پروفایل عادی را انجام می‌دهد؛ تغییر شناسه تنها با تصمیم ادمین مایکروسرویس دارای مجوز معتبر است.
- پروفایل فعال می‌تواند چند درخواست داشته باشد؛ پس از تغییر معتبر پروفایل، فقط پیش‌نویس‌های هنوز قابل‌ویرایش sync می‌شوند؛ درخواست‌های ارسال‌شده تاریخی دست‌نخورده می‌مانند.
- ادمین فقط اگر درخواست ارسال‌شده در جریان وجود نداشته باشد می‌تواند مشتری را بایگانی کند. پیش‌نویس‌های پیش از بایگانی برای همیشه read-only می‌شوند؛ بعد از restore هم باز نمی‌شوند.
- هیچ hard delete فراهم نشده است. ثبت نماینده شرکت در **پروفایل** پشتیبانی می‌شود؛ نماینده مختص درخواست، احراز صلاحیت/مدرک و تعداد دقیقاً یک نماینده در هر درخواست در فاز ۱۵ و Host انجام خواهد شد، نه با جعل تأیید در پروفایل.

## ۳. پیش‌نیاز نصب (بعداً، در شاخه آزمایشی)

1. ابتدا پچ‌های قدیمی و معوق (از جمله Audit) را طبق راهنمای فاز ۱۱ بازپخش و تست کنید؛ از وضعیت واقعی `git status` Snapshot امن بگیرید.
2. این فایل پچ را در ریشه `intl-platform` قرار دهید. پیش از اعمال، `git apply --check intl-phase13-service-recipients.patch` اجرا کنید. **ZIP و PATCH را با هم اعمال نکنید.**
3. در ریشه پلتفرم: `composer require 'intl/service-recipients:dev-main' -W` (با وجود path-repository `packages/*`).
4. در `apps/sandbox` با repository محلی: `composer require 'intl/service-recipients:dev-main' 'intl/service-recipients-laravel:dev-main' -W`.
5. `INTL_RECIPIENTS_DB_CONNECTION` را به دیتابیس **محلی همان مایکروسرویس** و `INTL_RECIPIENTS_HMAC_KEY` را به کلید اختصاصی تصادفی، پایدار و دارای حداقل ۳۲ بایت تنظیم کنید. کلید HMAC را از `APP_KEY` مستقل و در مدیریت اسرار نگهداری کنید. تغییر آن به برنامه migration/reindex نیاز دارد.
6. میزبان باید `RecipientRules`, `RecipientRequestBridge`, `RecipientAudit`, و قبلاً `PrincipalProvider`, `ScopeChecker` را با پیاده‌سازی واقعی و fail-closed در container متصل کند. بدون این bindingها استفاده عملیاتی ممنوع است. اگر از `ContractAuditBridge` استفاده می‌شود، نام رویدادها را در `intl-audit.allowed_events` ثبت کنید، اتصال Spatie Activitylog را روی **همان DB** قرار دهید و بررسی کنید رویدادها به‌صورت همزمان در تراکنش ثبت می‌شوند.
7. `php artisan migrate:status` و سپس migration **فقط در دیتابیس Sandbox**، نه NCR. پس از آن تست نمونه و تست کامل اجرا شود:

```bash
./vendor/bin/phpunit -c packages/service-recipients/phpunit.xml
cd apps/sandbox
php artisan test --filter=IntlServiceRecipientsTest
php artisan test
```

## ۴. الزامات امنیتی که هنوز مانع اتصال Production هستند

- رابط HTTP، Policy میزبان، Schema فیلدهای ملیتی و مسیر اعتبارسنجی خارجی در این پکیج hardcode نشده‌اند.
- `RecipientRequestBridge` باید **دقیقاً همان connection و transaction** بسته جدید را به‌کار ببرد. Fakeهای تست نمونه فقط قرارداد را شبیه‌سازی می‌کنند، نه تضمین واقعی Atomicity.
- پیش از نصب عملیاتی فاز ۱۵، Service Requests فعلی در create به‌جای فقط `RecipientVerifier` باید از **row lock روی Recipient** در همان تراکنش استفاده کند. در غیر این صورت ایجاد درخواست و بایگانی هم‌زمان race خواهد داشت. SQLite تست ساده برای تضمین رفتار هم‌زمانی MySQL/PostgreSQL کافی نیست.
- `ContractAuditBridge` فقط وقتی writer فعلی Audit روی **همان connection** کار کند قابل اتکا است. Audit حاوی متن شناسه هویتی، نام یا مدارک نباشد.
- پروفایل و اطلاعات نمایندگان با Laravel Crypt رمزگذاری می‌شوند. خروجی پاسخ HTTP و مدیریت نمایش/ماسک داده و عمر نگهداری اطلاعات باید در میزبان تعریف شود.
- رقابت روی سقف تعداد نماینده، تغییر شناسه، تغییر کلید و migrationهای چندمرحله‌ای روی DB مقصد آزمایش شود.
- `RecipientOwnershipVerifier` bridge اختیاری Service Requests را معرفی می‌کند ولی جایگزین atomic request gate نشده است.
- **اعتبارسنجی اختیار نماینده شرکت** (دستی، خارجی، ترکیبی، fallback قطعی و reuse) عمداً در این نسخه «پیاده‌شده» تلقی نمی‌شود؛ نیازمند host implementation و تست در فاز بعد است.

## ۵. نام رویدادها

`recipients.created`, `recipients.updated`, `recipients.identity.requested`, `recipients.identity.approved`, `recipients.identity.rejected`, `recipients.archived`, `recipients.restored`, `recipients.representative.added`, `recipients.representative.deactivated`.

## ۶. مجوزهای پیشنهادی Spatie

Agency: `recipients.create`, `recipients.list`, `recipients.view`, `recipients.update`, `recipients.identity.request`, `recipients.representative.add`, `recipients.representative.view`, `recipients.representative.deactivate`.

Platform Admin: `recipients.identity.decide`, `recipients.archive`, `recipients.restore`.

هیچ نقش به‌صورت پیش‌فرض مجوز ندارد. ScopeChecker متعلق به میزبان باید به‌جز کنترل‌های اضافه‌شده در سرویس، محدودیت‌های امنیتی خود را هم اعمال کند.
