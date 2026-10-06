# راهنمای نگهداری و نصب آینده فاز ۱۲

**اکنون:** فقط ZIP و Patch را بایگانی کن. روی `intl-platform` موجود یا NCR فایل نصب نکن. ZIP و Patch دو نمایش یک محتوا هستند؛ هنگام نصب فقط یکی را استفاده کن.

**بعداً، در کپی/شاخه آزمایشی و پس از Snapshot مخزن:**

1. وضعیت قبلی را مستند کن؛ پچ‌های فازهای ۸ Billing، ۹ Service Requests و ۱۰ Audit را به ترتیب با `git apply --check` بررسی و در صورت عدم نصب، اعمال کن. پچ Fix تست Documents را **دوباره** اعمال نکن.
2. پکیج‌های Core را در ریشه و Adapterها را در `apps/sandbox` نصب کن؛ در نصب پکیج‌های `dev-main` وابسته، همه پکیج‌های محلی لازم را صریحاً در همان `composer require ... -W` بیاور؛ `minimum-stability` کل پروژه را تغییر نده.
3. برای Audit، نصب `spatie/laravel-activitylog` و وجود دقیقاً یک Migration Activitylog روی **دیتابیس تست** را بررسی کن؛ هنگام استفاده از UUID/ULID نوع ستون‌های ID را تطبیق بده. هیچ Migration روی NCR اجرا نکن.
4. پس از گذر تک‌پکیجی‌ها، `intl-phase12-reference-host.patch` را در ریشه قرار بده و فقط **یک بار** اعمال کن:

```bash
cd ~/Desktop/intl-platform
git apply --check intl-phase12-reference-host.patch
git apply intl-phase12-reference-host.patch
php tools/phase12/verify-readiness.php --source-only
```

5. در Sandbox صرفاً با `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` یا DB تست جداگانه:

```bash
cd apps/sandbox
php artisan test --filter=IntlPhase12ReferenceFlowTest
php artisan test
```

6. سپس آزمون‌های منفی، اجرای DB مشابه مقصد، بررسی تمام تست‌های Core، تأیید نسخه‌ها و نسخه‌بندی Composer را انجام بده. تنها پس از تأیید مستند API مرکزی، اتصال واقعی پرداخت طراحی می‌شود.

**خیلی مهم:** Fake Gateway و Fake Scanner فقط داخل `tests/Feature/IntlPhase12ReferenceFlowTest.php` هستند. آن‌ها را در `AppServiceProvider`، کانفیگ Production یا مسیر HTTP ثبت نکن. این بسته هیچ Controller، روتر یا سرویس Runtime تولید نمی‌کند. الگوی اتصال واقعی در `CONTRACT_MATRIX_FA.md` مشخص است.
