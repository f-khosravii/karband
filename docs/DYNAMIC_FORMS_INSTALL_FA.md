# راهنمای نصب Dynamic Forms در INTL Platform

## مرز معماری

این بسته فقط برای یک مایکروسرویس مستقل نصب می‌شود: کد پکیج‌ها مشترک است، ولی تعاریف و پاسخ‌ها در دیتابیس **همان سرویس** ذخیره می‌شوند. یک فرم منتشرشده به هر خدمت تعلق دارد و با شماره نسخه شناسایی می‌شود. دسته‌بندی خدمات، مدیریت فایل، اتصال مستقیم NCR، فرم‌ساز گرافیکی و تعریف دلخواه منطق PHP داخل پنل جزو این فاز نیستند.

روش Hybrid: ادمین مایکروسرویس در آینده از طریق پنل به متدهای `saveDraft`، `draft`، `publishDraft` دسترسی خواهد داشت. قوانین تجاری پیچیده را برنامه‌نویس با `CustomRule` ثبت می‌کند؛ متن فرم هیچ کدی را اجرا نمی‌کند.

## نصب در پروژه

۱. یکی از بسته‌های ZIP یا Patch را استفاده کنید. برای Patch، آن را داخل ریشه `intl-platform` قرار دهید:

```bash
git apply --check intl-dynamic-forms.patch
git apply intl-dynamic-forms.patch
composer require 'intl/dynamic-forms:dev-main' -W
```

۲. در Laravel Sandbox (از ریشه پلتفرم):

```bash
cd apps/sandbox
composer config repositories.intl-local path '../../packages/*'
composer require 'intl/dynamic-forms:dev-main' 'intl/dynamic-forms-laravel:dev-main' -W
php artisan package:discover
php artisan test --filter=IntlDynamicFormsTest
php artisan test
```

> اجرای migrationها صرفاً داخل دیتابیس تست Sandbox. `RefreshDatabase` هنگام تست، migrationهای پکیج را اجرا می‌کند. فعلاً روی NCR اجرا نکنید.

۳. تست‌های مستقل هسته:

```bash
cd ../..
./vendor/bin/phpunit -c packages/dynamic-forms/phpunit.xml
php -d zend.assertions=1 -d assert.exception=1 packages/dynamic-forms/tools/standalone-smoke.php
```

۴. برای اینکه `./vendor/bin/phpunit` در ریشه این تست‌ها را هم اجرا کند، در فایل **ریشه** `phpunit.xml` داخل `testsuites` اضافه کنید:

```xml
<testsuite name="Dynamic Forms"><directory>packages/dynamic-forms/tests</directory></testsuite>
```

## دسترسی‌ها (هیچ موردی خودکار اعطا نمی‌شود)

- `forms.definition.edit` و `forms.definition.publish` برای ادمین مجاز مایکروسرویس؛ `forms.definition.view` برای مشاهده تعریف توسط ادمین‌های مجاز.
- `forms.response.submit` و `forms.response.view` روی شناسه **واقعی درخواست**، مطابق دسترسی و مالکیت تعیین‌شده در `ScopeChecker` هر مایکروسرویس. در `ScopeChecker` درخواست باید از منبع واقعی خوانده شود و وضعیت گردش کار آن نیز در صورت لزوم کنترل گردد؛ به کد خدمت یا مالکیت ارسال‌شده توسط کلاینت اعتماد نکنید.
- `catalog.service.use` برای استفاده نمایندگی از یک خدمت خاص و فعال.
- ادمین سازمان در حالت نمونه می‌تواند فرم و پاسخ‌ها را بخواند، اما اجازه ویرایش یا انتشار ندارد؛ این موارد باید در برنامه هر سازمان صریحاً اعطا شوند.

### نسخه‌بندی و پیوستگی با Workflow

**قبل از اولین پاسخ**، هنگام ایجاد درخواست جدید، متد `FormAnswers::reserve(serviceCode, resourceType, resourceId)` را صدا بزنید تا نسخه همان روز ذخیره شود. در اپ میزبان `reserve()` و `CatalogWorkflowStarter::start()` را تحت یک تراکنش بیرونی روی دیتابیس یکسان اجرا کنید. هیچ درخواست قبلی با انتشار فرم جدید خودکار جابه‌جا نمی‌شود. `save()` دارای نسخه‌سنجی بازنویسی با `expectedRevision` است.

### افزودن قانون کدنویسی‌شده

با پیاده‌سازی `Intl\DynamicForms\Contracts\CustomRule` و Binding کردن `RuleRegistry` به `MapRuleRegistry` دارای قواعد مجاز در Service Provider اپلیکیشن، منطق پیچیده فقط توسط برنامه‌نویس اضافه می‌شود. اگر یک Rule هنگام انتشار وجود نداشته باشد یا هنگام اعتبارسنجی خطا بدهد، عملیات fail-closed می‌شود. از تزریق کلاس، expression یا callback توسط متن فرم جلوگیری شده است.

### موارد خارج از نسخه اول

فرم‌ساز گرافیکی، endpointهای API، اتصال به دیتابیس عملیاتی NCR، File Upload، ارسال مدارک، رمزگذاری سفارشی و مهاجرت نسخه فرم‌های موجود در این نسخه پیاده نشده‌اند. ذخیره داده‌های حساس نیازمند طراحی مجزای حریم خصوصی، رمزگذاری، دسترسی و پاک‌سازی اطلاعات است.
