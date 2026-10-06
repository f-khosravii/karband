# فاز ۱۹ — اتصال معتبر سیاست مالی و برنامه آزمون مرحله‌ای

**وضعیت:** پچ اصلاحی **آماده آزمایش در آینده**؛ روی محیط واقعی یا پروژه محلی کاربر اجرا نشده است. فاز ۱۹ فقط شکافِ «منبع معتبر قیمت + سیاست استرداد در یک Snapshot» را در سطح قرارداد و Bridge محلی پوشش می‌دهد؛ اتصال واقعی پیشخوان و تأیید نمایندگان حقوقی را شبیه‌سازی **نمی‌توان** به‌عنوان تکمیل عملیاتی گزارش کرد.

## تغییرات کد

- `ServiceFinancialTerms::fromCatalogSettings()` تنظیمات محلی `billing_fixed_price_minor` (عدد صحیح مثبت)، `billing_currency` و `refund_events` (حتی آرایه خالیِ صریح) را اعتبارسنجی می‌کند؛ فقدان/مقدار نامعتبر به‌صورت Fail-Closed رد می‌شود. کلیدها مطابق Fixture موجود فاز ۱۷ هستند؛ واحد ریال/تومان از روی نام کلید **قابل اثبات نیست** و باید طبق API واقعی تأیید شود.
- `LocalCatalogFinancialTermsProvider` قیمت و سیاست استرداد را از **یک ردیف واحد و یک revision** می‌خواند، اما تنها پس از بررسی مالکیت، تطابق خدمت و مجازبودن پرداخت توسط `PayableRequestGuard` اختصاصی میزبان و برقراری اتصال به **همان دیتابیس محلی**. هیچ پیاده‌سازی سهل‌گیر پیش‌فرضی برای این Guard ثبت نشده است.
- `PaymentService` به‌صورت Opt-in یک `TrustedFinancialTermsProvider` جدید می‌پذیرد؛ هنگام فعال‌بودن آن، ایجاد تلاش جدید، تلاش مجدد پس از شکست قطعی و Refresh تلاشِ ارسال‌نشده از یک Snapshot مشترک مبلغ/سیاست استفاده می‌کنند. سازگاری سازنده قبلی حفظ شده تا تست‌ها و میزبان قبلی در حین مهاجرت نشکنند. **برای محیط واقعی باید مسیر Snapshot ترکیبی صریحاً Bind شود و `intl-billing.require_atomic_financial_terms` حتماً `true` باشد.**
- هیچ API عمومی قیمت/سیاست استرداد در اختیار نمایندگی قرار نمی‌گیرد؛ میزبان مبلغ Freeze‌شده همان تلاش را به کاربر نمایش و تأیید می‌گیرد. Ledger مالی در پکیج Billing باقی می‌ماند.

## ثبت Binding در میزبان (شبه‌کد، نه کد آماده Production)

۱. `PayableRequestGuard` را برای مایکروسرویس خود پیاده‌سازی کن: Principal معتبر، تعلق درخواست به Agency همان نشست، تطابق خدمت و بررسی وضعیت فعلی Workflow **نسخه Pin‌شده** برای زمان پرداخت را از دیتابیس محلی بخوان؛ نبود هر داده/خطا = منع.

۲. `LocalCatalogFinancialTermsProvider` را صریحاً بساز و به `TrustedFinancialTermsProvider` و `TrustedQuoteProvider` مرتبط کن. **برای فعال‌کردن مسیر جدید باید PaymentService را در IoC با named argument `financialTerms: $provider` بسازی**؛ صرف Bind کردن Interface بدون این مرحله تضمین نمی‌کند Laravel از پارامتر اختیاری استفاده کند. `TrustedRefundPolicyProvider` هم تا زمان حذف مسیر قدیمی باید صریحاً Bind شود. این Bridge هیچ Binding عملیاتی خودکاری ثبت نمی‌کند.

۳. تنظیمات Catalog هنگام ایجاد/ویرایش خدمت از مسیر مدیریت معتبر و مجاز، با `ServiceFinancialTerms::fromCatalogSettings()` اعتبارسنجی شود. در این اصلاحیه Validation در مدل عمومی Catalog اجبار نشده تا قابلیت خدمات موجودی که هنوز به فیلدهای مالی مهاجرت نکرده‌اند نشکند. در محیط واقعی مرحله انتشار Service Settings باید آن را اجباری کند.

۴. اگر قیمت/سیاست در لحظه ایجاد تلاش خوانده شد و بعد تنظیمات کاتالوگ تغییر کرد، Attempt قبلی بازنویسی نشود؛ فقط تلاش جدیدی که مجاز است Snapshot جدید بگیرد. پیوند revision کاتالوگ در ساختار جدید `FinancialTermsSnapshot` وجود دارد؛ **ذخیره‌سازی دائمی revision در ledger به‌صورت مستقل هنوز توسعه داده نشده است.** برای ردیابی کامل تعرفه این موضوع قبل از Production تکمیل شود.

## محدودیت حیاتی که این پچ حل نمی‌کند

- API واقعی `CentralPaymentGateway` و `CentralRefundGateway` هنوز مشخص نیست؛ حتی تستِ Fake نمی‌تواند تعهد Idempotency یا Lookup در پیشخوان را اثبات کند. تا امضای قرارداد و تست رسمی، فقط Fake در Sandbox مجاز است.
- `RequestSubmissionVerifier` باید با مرجع واقعی احراز اختیار نماینده شخص حقوقی پیاده‌سازی شود؛ سند خارجی/مجوز Fallback/انقضا/ابطال هنوز اتصال واقعی ندارد. تأیید اختیار ≠ صرفاً انتخاب نماینده از پروفایل.
- `TrustedRefundEventVerifier` باید وقوع واقعی و تعلق رویداد رد/لغو/خطای صدور را بررسی کند و اجازه تولید رویداد توسط ورودی کاربر ندهد.
- تست هم‌زمانی روی DB واقعی، اجرای Laravel/Composer روی پروژه زنده، نصب Migrationها، اسکنر واقعی فایل، Worker رفع حالت مالی unknown و رصد خطاها هنوز باقی هستند.

## ترتیب امن نصب آینده

۱. روی کپی/Branch تازه از پروژه و بدون اجرای `git clean` یا `git reset --hard`، نخست مبنای تست‌شده تا Documents را تأیید کن.

۲. فاز ۸ Billing و سپس فاز ۱۴؛ بعد فاز ۹ Service Requests و فاز ۱۰ Audit؛ سپس فاز ۱۳ Service Recipients و فاز ۱۵ اصلاح Request؛ بعد فاز ۱۲ Reference Host اولیه و اصلاحیه فاز ۱۷. ZIP و Patch یک فاز را همزمان اضافه نکن.

۳. **این پچ فاز ۱۹ را پس از فازهای ۱۴ و ۱۷** اعمال کن. در محیط آزمایشی با `git apply --check` شروع کن، ابتدا Core تست و سپس Featureهای اختصاصی و مجموعه کامل را اجرا کن. فازهای ۱۱، ۱۶ و ۱۸ عمدتاً ابزار/مستندات‌اند و نباید پچ‌های قبلی بایگانی‌شده داخل ZIP آن‌ها دوباره اعمال شوند.

۴. برای میزبان واقعی، آزمون‌های منفی missing Guard، دسترسی نمایندگی دیگر، تغییر غیرمجاز قیمت، policy ناقص، payment unknown و رقابت درخواست‌ها را روی دیتابیس مشابه مقصد اجرا کن. **هیچ اجازه امنیتی برای سبزشدن تست حذف نشود.**

## دستورات بررسی، صرفاً برای زمان دسترسی به پروژه

```bash
cd ~/Desktop/intl-platform
git apply --check intl-phase19-catalog-financial-bridge.patch
# پس از بررسی Diff و تهیه Snapshot امن:
git apply intl-phase19-catalog-financial-bridge.patch
./vendor/bin/phpunit -c packages/service-catalog/phpunit.xml
cd apps/sandbox
composer update intl/billing-laravel intl/service-catalog-laravel -W
php artisan test --filter=IntlPhase19CatalogFinancialTermsTest
php artisan test
```

**هشدار:** `composer update` ممکن است Lockfile را تغییر دهد؛ فقط در Branch آزمایشی و با بازبینی Diff. هیچ `migrate:fresh` یا اجرای عملیاتی روی NCR مجاز نیست.
