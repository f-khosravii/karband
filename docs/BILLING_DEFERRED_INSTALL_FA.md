> **توجه: این راهنمای فاز ۸ است. پس از اعمال اصلاح فاز ۱۴، مرجع جدید `docs/PHASE14_BILLING_PRICE_REFUND_FA.md` است؛ دستورها و محدودیت‌های قدیمی مربوط به retry و API اصلاح شده‌اند.**

# فاز هشتم INTL — بسته Billing برای نصب **در مرحله آخر**

## تصمیم معماری تثبیت‌شده

- تمام مایکروسرویس‌های سازمان‌های مختلف از **یک سامانه پرداخت مرکزی پیشخوان** استفاده می‌کنند. این تنها وابستگی بیرونی مشترک برای پرداخت است؛ پایگاه داده، سرور و شبکه هر سازمان همچنان مستقل‌اند.
- مایکروسرویس با **مفهوم پرداخت** کار می‌کند: شناسه پرونده، خدمت، نمایندگی، مبلغ معتبر، وضعیت پرداخت، شناسه پیگیری. نه جدول کیف پول دارد، نه موجودی می‌خواند، نه برداشت وجه یا مدیریت کیف پول انجام می‌دهد. این مسئولیت منحصراً سامانه مرکزی است.
- `intl/billing` هسته مستقل و `intl/billing-laravel` ثبت محلی + قرارداد `CentralPaymentGateway` هستند. پکیج راه‌اندازی و مدیریت کیف پول ندارد.
- جزئیات API واقعی پیشخوان به دست ما نرسیده؛ در این پچ **هیچ endpoint یا پاسخ ساختگی** را به‌عنوان API واقعی معرفی نکرده‌ایم. اتصال واقعی باید در یک Adapter زیرساختی جدا با دریافت مستندات API نوشته شود.

## وضعیت تحویل فعلی

**فعلاً هیچ Patch، Migration، Composer require یا Test را اجرا نکنید.** فایل Patch و ZIP این فاز را کنار سایر Patchهای پروژه نگه دارید. برای نصب آینده فقط **یکی** از دو روش ZIP یا Patch را انتخاب کنید. فایل‌های موجود پروژه با این Patch جایگزین نمی‌شوند؛ فقط فایل‌های جدید اضافه می‌شوند. فایل‌های `intl-*.patch` نباید اشتباهاً با `git add -A` در مخزن ثبت شوند.

## مراحل پیشنهادی در مرحله ادغام نهایی (بعداً)

۱. از ریشه `intl-platform`، بعد از اعمال پچ‌های پیش‌نیاز، فقط در شاخه کاری آزمایشی:

```bash
cd ~/Desktop/intl-platform
git apply --check intl-billing.patch
git apply intl-billing.patch
composer require 'intl/billing:dev-main' -W
./vendor/bin/phpunit -c packages/billing/phpunit.xml
```

۲. در Sandbox (و **نه NCR یا Production**)، هر دو پکیج را مستقیم نصب کنید تا محدودیت `minimum-stability` روی dependency توسعه‌ای مانع نصب نشود:

```bash
cd apps/sandbox
composer config repositories.intl-local path '../../packages/*'
composer require 'intl/billing:dev-main' 'intl/billing-laravel:dev-main' -W
php artisan package:discover
php artisan optimize:clear
php artisan test --filter=IntlBillingTest
php artisan test
```

۳. بعد از اینکه تمام بسته‌ها واقعاً روی پلتفرم اعمال شدند، در ریشه `phpunit.xml` داخل `<testsuites>` می‌توانید این مجموعه را اضافه کنید:

```xml
<testsuite name="Billing"><directory>packages/billing/tests</directory></testsuite>
```

۴. پس از تأیید تست‌های Sandbox و مستندات واقعی پیشخوان، Adapter عملیاتی را به `CentralPaymentGateway` و اعتبارسنجی پرونده/قیمت را به `TrustedQuoteProvider` متصل کنید. هیچ Binding پیش‌فرض allow-all وجود ندارد. دسترسی‌ها با Spatie و `ScopeChecker` واقعی کنترل شوند؛ نقش پیش‌فرض `agency` برای ایجاد/پرداخت به‌تنهایی کافی نیست و Permission مستقل هم لازم است.

## دسترسی‌ها و اتصال Workflow

- مجوزهای مورد نیاز: `billing.payment.create`, `billing.payment.view`, `billing.payment.execute`, `billing.payment.reconcile`, `billing.payment.retry`.
- `TrustedQuoteProvider` باید قیمت نهایی را از Backend، تعلق پرونده به خدمت و نمایندگی، مجازبودن پرداخت در وضعیت Workflow و شرایط بیزینسی بررسی کند. مبلغ یا نمایندگی نباید از ورودی کلاینت به‌عنوان داده معتبر قبول شود.
- `TrustedPaymentFacts::isConfirmed(serviceCode, resourceType, resourceId)` برای تزریق **فکت پرداخت تأییدشده** به `TrustedFactProvider` در Workflow استفاده می‌شود. خود پکیج Billing وضعیت Workflow را تغییر نمی‌دهد. ارسال Action بعد از پرداخت باید در لایه Orchestration همان مایکروسرویس با مکانیزم Idempotency انجام شود.

## پروتکل ضروری جلوگیری از پرداخت دوباره

۱. ابتدا `create()` فقط یک قصد پرداخت محلی و مبلغ معتبر ثبت می‌کند.
۲. `dispatch()` ابتدا `processing` را در دیتابیس خودش ثبت و تراکنش را Commit می‌کند؛ سپس تنها **یک بار در هر Attempt** درخواست را با کلید پایدار به سامانه مرکزی می‌فرستد.
۳. موفقیت قطعی با Reference معتبر، `succeeded` است. خطای شبکه، timeout، پاسخ نامعتبر، 5xx یا استعلام مبهم حتماً `unknown` است، نه `failed`.
۴. برای `unknown`، `pending` یا `processing` قدیمی، فقط `reconcile()` که **صرفاً استعلام می‌کند** مجاز است؛ نباید دوباره درخواست برداشت ارسال شود.
۵. فقط اگر سامانه مرکزی به‌طور قطعی «هیچ مبلغی کسر نشده» را تأیید کرده باشد، `failed` و تلاش جدید با کلید جدید مجاز است.
۶. اگر پیشخوان Idempotency قابل اتکا و استعلام معتبر با همان کلید درخواست را پشتیبانی نکند، **Dispatch عملیاتی را فعال نکنید**؛ بدون این قابلیت‌ها، جلوگیری قابل اتکا از پرداخت تکراری تضمین‌پذیر نیست.

**نکته پولی:** مبلغ عدد صحیح در **واحد مورد توافق API واقعی** ذخیره می‌شود؛ واحد ریال/تومان و Currency را قبل از اتصال با پیشخوان تأیید کنید. هیچ تبدیل واحد حدسی یا عدد اعشاری در پکیج مجاز نیست.

**خارج از محدوده این پچ:** اتصال واقعی به API پیشخوان (تا دریافت مستندات)، ساخت پنل و Route، Migration عملیاتی NCR، Refund، چند مرحله‌ای‌شدن مالی، صورت‌حساب رسمی، زمان‌بندی Job پیگیری، و مانیتورینگ استقرار. پکیج فقط آماده ادغام آزمایشی آینده است، نه انتشار عملیاتی.
