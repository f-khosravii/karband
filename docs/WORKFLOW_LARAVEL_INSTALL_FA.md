# نصب Laravel Workflow Adapter در INTL Platform

این بسته فاز سوم است و پکیج `intl/workflow` فاز دوم را **تغییر نمی‌دهد**. دو خروجی ZIP و Patch یکسان‌اند: فقط یکی را اعمال کن.

## ۱. اعمال پچ روی Monorepo

در Git Bash:

```bash
cd ~/Desktop/intl-platform
git status --short
git apply --check /path/to/intl-workflow-laravel.patch
git apply /path/to/intl-workflow-laravel.patch
```

اگر ZIP را انتخاب کردی، محتویات را مستقیماً در ریشه `intl-platform` استخراج کن. مسیرهای جدید باید `packages/workflow-laravel/`، `apps/sandbox/tests/Feature/IntlWorkflowAdapterTest.php` و `docs/WORKFLOW_LARAVEL_INSTALL_FA.md` باشند. **پچ هیچ فایل موجودی را بازنویسی نمی‌کند.**

## ۲. نصب فقط در Sandbox، نه NCR

اپ `apps/sandbox` یک پروژه مستقل Composer است. نصب پکیج Workflow در Composer ریشه الزاماً آن را در Sandbox نصب نکرده است:

```bash
cd ~/Desktop/intl-platform/apps/sandbox
composer config repositories.intl-local path '../../packages/*'
composer require 'intl/workflow:dev-main' 'intl/workflow-laravel:dev-main' -W
php artisan package:discover
php artisan migrate:status
```

اگر repository path از قبل وجود دارد، اضافه‌کردن مجددش ضروری نیست. `php artisan migrate:status` باید migration پکیج را نمایش دهد. اگر هنوز اعمال نشده، **فقط با دیتابیس تست جداگانه** migration را اجرا کن:

```bash
php artisan migrate --pretend
php artisan migrate
```

قبل از اجرای `migrate`، مقادیر `.env` خود Sandbox را بررسی کن تا تصادفاً روی دیتابیس دیگری کار نکنی. برای تست، فایل `.env.testing` باید یک دیتابیس ایزوله داشته باشد؛ SQLite در حافظه گزینه خوبی است.

## ۳. تست

```bash
php artisan test --filter=IntlWorkflowAdapterTest
php artisan test
cd ../..
./vendor/bin/phpunit
```

انتظار ما اضافه‌شدن **۱۹ تست Integration** جدید به تست‌های Sandbox است. مجموعه تست ریشه قبلاً ۴۵ تست داشته است و قرار نیست تست Laravel را داخل PHPUnit ریشه وارد کنیم؛ چون ریشه Laravel را به‌عنوان اپلیکیشن راه‌اندازی نمی‌کند.

## ۴. قراردادهای امنیتی اجباری

این Adapter بدون اتصال دستی برنامه به دو Interface زیر قابل استفاده عملیاتی نیست:

- `Intl\WorkflowLaravel\Contracts\ActorResolver`: بازیگر را از سیستم ورود واقعی برنامه می‌گیرد؛ هیچ role یا agency IDای از داده خام کلاینت دریافت نکند.
- `Intl\WorkflowLaravel\Contracts\WorkflowAuthorizer`: قبل از انتشار، شروع، اعمال اقدام و مشاهده، دسترسی واقعی به همان درخواست و سازمان مربوطه را بررسی و در صورت عدم مجوز Exception ایجاد کند.

سه بازیگر مستقیم فعلی: `agency`، `government_admin` و `platform_admin` فقط مثال نقش‌های میزبان هستند؛ در Engine ثابت نشده‌اند. مشتری نمایندگی `ServiceRecipient` است و به‌عنوان actor وارد Workflow نمی‌شود. اگر نمایندگی بعدها چند کارمند پیدا کند، موتور تغییری نمی‌کند؛ میزبان `actorId` کارمند و `organizationId` نمایندگی را ارائه می‌کند.

`TrustedFactProvider` اختیاری است و به‌صورت پیش‌فرض آرایه خالی برمی‌گرداند. برای شرط‌هایی مانند کامل‌بودن مدارک، میزبان باید اینترفیس را پیاده‌سازی کند و `TransitionGuard`های موردنیاز را در فایل تنظیمات `intl-workflow.php` ثبت نماید.

## ۵. معنای عملیات

- `DefinitionCatalog::publish($definition)` تنها با مجوز صریح منتشر می‌کند؛ نسخه منتشرشده از مسیر سرویس قابل ویرایش نیست.
- `WorkflowRuntime::start($workflowId, $version, $type, $id)` نسخه دقیق را به درخواست متصل می‌کند.
- `WorkflowRuntime::apply($instanceId, $action, $comment, $idempotencyKey)` ردیف را در تراکنش قفل کرده، وضعیت/نقش/شرط را دوباره ارزیابی می‌کند و تغییر وضعیت + تاریخچه را با هم ذخیره می‌کند.
- `view()` و `history()` نیز احراز هویت و مجوز میزبان را بررسی می‌کنند.
- درخواست‌های ردشده تاریخچه موفق و کلید idempotency ثبت نمی‌کنند. ارسال دوباره یک اقدام موفق با همان کلید، **اقدام مجدد را اجرا نمی‌کند**.

## ۶. قبل از اتصال به NCR

این پچ **روی کد، جداول، endpointها و گردش مالی NCR هیچ تغییری نمی‌دهد**. وضعیت‌های عددی فعلی NCR، ACL واقعی سازمان‌ها، guardهای احراز پرداخت و جلوگیری از پرداخت دوباره باید هنگام تهیه NCR Adapter جداگانه نگاشت و تست شوند. عملیات شبکه یا wallet را داخل تراکنش قابل تکرار این پکیج انجام نده. برای جلوگیری از دوگانگی وضعیت بین جدول اختصاصی میزبان و جدول Workflow، به تراکنش مشترک روی یک دیتابیس و یکسان‌بودن ترتیب گرفتن Lock نیاز داریم.

## ۷. مسیر برگشت

تا وقتی Sandbox تنها مصرف‌کننده است، فایل‌های جدید قابل حذف‌اند و پروژه NCR دست‌نخورده می‌ماند. قبل از حذف جدول‌ها، اگر داده تستی یا واقعی ذخیره شده است حتماً Backup بگیر. در صورت نصب‌شدن این پکیج در یک اپ واقعی، حذف composer package بدون برنامه migration وضعیت و تاریخچه، مجاز نیست.
