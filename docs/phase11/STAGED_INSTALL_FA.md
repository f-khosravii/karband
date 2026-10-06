# راهنمای نصب مرحله‌ای آینده — صرفاً Sandbox

**در حال حاضر اجرا نکنید.** این راهنما برای زمانی است که به پروژه و امکان تست دسترسی دارید. اگر فاز مربوطه قبلاً در مخزن نصب شده، پچ آن را دوباره اعمال نکنید. ZIP و Patch دو روش جایگزین‌اند، نه مراحل متوالی.

## مرحله صفر — حفظ وضعیت فعلی

- از فولدر پروژه و فایل‌های مهم توسعه نسخه پشتیبان مستقل تهیه کنید؛ مخصوصاً چون در گزارش Git قبلی فایل‌های staged، modified و untracked زیادی وجود داشته است. `git stash`, `git clean -fd`, `git reset --hard` یا `migrate:fresh` را بدون بررسی اختصاصی اجرا نکنید.
- `git status --short`, `php -v`, `composer --version`, نسخه Laravel، `git diff --check` و نام Connection تست را ثبت کنید. اجرای `php artisan test` فقط روی Sandbox و DB تست مستقل؛ نه NCR یا production.
- فایل‌های `intl-*.patch` و فایل‌های ZIP را **خارج از مخزن** در فولدر آرشیو نگه دارید؛ برای دستور `git apply` می‌توان مسیر کامل آن‌ها را نوشت (مثلاً `git apply --check /path/to/file.patch`).
- پیش‌نیازهای نصب‌شده قبلی: Contracts، Workflow، Authorization/Spatie Permission، Service Catalog، Dynamic Forms و Documents. اگر یکی نصب نشده، ابتدا طبق README همان فاز آن را در Sandbox اضافه و تست کنید. Fix تست Documents قبلاً روی مخزن خودتان انجام شده؛ دوباره اعمال نشود.

## مرحله اول — پیش‌نیازها و بررسی ایستا

```bash
cd ~/Desktop/intl-platform
php tools/verify-platform-release.php
./vendor/bin/phpunit
cd apps/sandbox
php artisan test
```

تست‌های قابل استناد پیشین قبل از سه پکیج جدید: ۱۱۱ تست Sandbox و ۱۱ تست مستقل Documents. عدد خروجی تازه ممکن است پس از اصلاح تست‌ها فرق کند؛ هدف **صفر شکست جدید** است نه رسیدن به عدد از پیش تعیین‌شده.

## مرحله دوم — Billing در مخزن و Sandbox

```bash
# بعد از اطمینان از نبود فایل‌های این پچ در مخزن
cd ~/Desktop/intl-platform
git apply --check /path/to/intl-billing.patch
git apply /path/to/intl-billing.patch
composer require 'intl/billing:dev-main' -W
./vendor/bin/phpunit -c packages/billing/phpunit.xml

cd apps/sandbox
composer config repositories.intl-local path '../../packages/*'
composer require 'intl/billing:dev-main' 'intl/billing-laravel:dev-main' -W
php artisan package:discover
php artisan migrate:status
php artisan test --filter=IntlBillingTest
php artisan test
```

اینجا `CentralPaymentGateway` فقط Fake تست است. بدون مستند API واقعی پیشخوان و تضمین قابل‌آزمون Idempotency/Lookup، پیاده‌سازی و ارسال واقعی ممنوع است. هیچ تستی نباید به کیف پول واقعی متصل شود.

## مرحله سوم — Service Requests

```bash
cd ~/Desktop/intl-platform
git apply --check /path/to/intl-service-requests.patch
git apply /path/to/intl-service-requests.patch
composer require 'intl/service-requests:dev-main' -W
./vendor/bin/phpunit -c packages/service-requests/phpunit.xml

cd apps/sandbox
composer require 'intl/service-requests:dev-main' 'intl/service-requests-laravel:dev-main' -W
php artisan package:discover
php artisan migrate:status
php artisan test --filter=IntlServiceRequestsTest
php artisan test
```

اگر Composer درباره Stability پکیج داخلی توسعه‌ای اعتراض کرد، **همه پیش‌نیازهای داخلی همان دستور را صریحاً با `:dev-main` اضافه کنید**؛ `minimum-stability` کل پروژه را بدون تحلیل به `dev` تغییر ندهید. اجرای تست فاز نهم منوط به اتصال Fake موردنیاز میزبان از تست و قرار داشتن همه metadataهای Request/Catalog/Workflow/Forms/Documents روی همان Connection تست است.

## مرحله چهارم — Audit (مستقل از فاز ۸ و ۹ در سطح Composer)

```bash
cd ~/Desktop/intl-platform
git apply --check /path/to/intl-audit-laravel.patch
git apply /path/to/intl-audit-laravel.patch
./vendor/bin/phpunit --bootstrap vendor/autoload.php packages/contracts/tests/AuditEventTest.php

cd apps/sandbox
composer require 'intl/contracts:dev-main' 'intl/authorization-laravel:dev-main' 'intl/audit-laravel:dev-main' -W
php artisan package:discover
php artisan migrate:status
```

قبل از تست Audit **اگر** Spatie Activitylog Migration هنوز نصب نشده، یک‌بار منتشر کنید:

```bash
php artisan vendor:publish --provider='Spatie\Activitylog\ActivitylogServiceProvider' --tag='activitylog-migrations'
```

Migration منتشرشده را پیش از اجرای هر migrate از نظر `causer_id` و `subject_id` برای کلیدهای UUID/ULID، اتصال DB محلی و تداخل با migration قبلی بازبینی کنید. تنها روی Sandbox:

```bash
php artisan test --filter=IntlAuditTest
php artisan test
```

Spatie Permission و Activitylog دو پکیج متفاوت‌اند؛ Migration یکی، جدول دیگری را ایجاد نمی‌کند. افزون بر نصب، پیکربندی `allowed_events` و ثبت صریح رویداد در نقاط فراخوانی نیز لازم است.

## مرحله پنجم — بررسی سراسری و ثبت نتایج

```bash
cd ~/Desktop/intl-platform
php tools/verify-platform-release.php --complete
./vendor/bin/phpunit -c packages/workflow/phpunit.xml
./vendor/bin/phpunit -c packages/service-catalog/phpunit.xml
./vendor/bin/phpunit -c packages/dynamic-forms/phpunit.xml
./vendor/bin/phpunit -c packages/documents/phpunit.xml
./vendor/bin/phpunit -c packages/billing/phpunit.xml
./vendor/bin/phpunit -c packages/service-requests/phpunit.xml
./vendor/bin/phpunit

cd apps/sandbox
php artisan test
composer validate
```

توجه: `phpunit.xml` اصلی پلتفرم ممکن است هنوز همه Core suiteها را شامل نشود؛ اجرای دستور جداگانه بالا مهم است. هشدارهای `dev-main` یا قیود توسعه‌ای را برای انتشار رسمی حل کنید. تا زمان برچسب نسخه واقعی نباید `dev-main`ها را کورکورانه به شماره نسخه فرضی تبدیل کرد.

## مرحله ششم — Release Candidate و نه Production

- در محیط غیرتولیدی با موتور واقعی DB مقصد، تست رقابت هم‌زمان، rollback و idempotency را اجرا کنید.
- Permission و Scope هر نقش را با Guard واقعی سازمان امتحان کنید؛ همه وابستگی‌های امنیتی میزبان با حالت fail-closed Bind شوند.
- با **Stub/Fake قابل کنترل** قطعی/Timeout پرداخت را شبیه‌سازی کنید؛ فراخوانی واقعی مرکزی تا تأیید قرارداد ممنوع است.
- برای Audit، تراکنش محلی مشترک و عدم نشت اطلاعات حساس، عدم وجود صفحه نمایش عمومی، migration و retention را بررسی کنید.
- تا تأیید تست پذیرش جداگانه NCR، هیچ انتقال درخواست‌های باز یا Migration تولیدی انجام ندهید.

## برگشت و عیب‌یابی

- در هر مرحله که تست مربوط شکست خورد، ادامه نصب را متوقف و خروجی دستور، وضعیت Composer lock و diff مرحله را نگه دارید.
- قبل از هر تغییر از شاخه یا Snapshot مستقل استفاده کنید. اعمال معکوس پچ (`git apply -R`) **فقط زمانی** مجاز است که مطمئنید فایل‌های آن پچ از زمان افزودن ویرایش نشده‌اند؛ در غیر این صورت از نسخه پشتیبان/commit ویژه همان مرحله استفاده کنید.
- Migration برگشتی ممکن است داده آزمایشی را پاک کند؛ به اجرای خودکار `migrate:rollback` روی هر DB ناشناخته اتکا نکنید.
