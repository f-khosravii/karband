# فاز ۱۲ — ماتریس تست و معیار پذیرش

## در بسته حاضر (بعد از نصب پچ‌های معوق)

| تست | معیار پذیرش |
|---|---|
| `test_complete_flow_correction_replacement_unknown_payment_and_reconciliation` | نسخه‌ها Pin؛ Submit زودهنگام رد؛ فرم و PDF ذخیره؛ توضیح اصلاح لازم؛ جایگزینی فقط در اصلاح؛ پس از تأیید پرداخت گام صدور؛ `chargeCalls=1` و `lookupCalls=1`؛ Audit محدود |
| `test_foreign_agency_cannot_view_or_create_for_foreign_recipient` | نمایندگی دیگر نه پرونده را می‌بیند نه برای مشتری غیرمجاز پرونده می‌سازد |
| `test_no_request_is_created_when_recipient_directory_denies` | شکست تایید مشتری باعث rollback محلی Request/Workflow/Documents می‌شود |
| `test_payment_cannot_be_initiated_before_approved_state` | پیش از تأیید هیچ Intent و هیچ تماس مرکزی ایجاد نمی‌شود |
| `test_audit_rejects_unapproved_personal_data_metadata` | داده شخصی خارج از فهرست مجاز Audit رد می‌شود |

این تست‌ها **نوشته شده‌اند اما در محیط واقعی Laravel اجرا نشده‌اند**. تست‌های تک‌پکیجی Billing، Service Requests و Audit همچنان جداگانه باید قبل از سناریوی ترکیبی اجرا شوند. نتیجه تست‌های قبلی Sandbox صرفاً به نسخه قبلی بسته‌ها مربوط است.

## هنگام اجرای نهایی

1. در شاخه/کپی مجزای Sandbox، پچ‌های نصب‌نشده را به ترتیب راهنمای اصلی نصب و Smoke/Unit تک‌پکیجی اعمال کنید.
2. Migrationها را روی DB تست مستقل بررسی کنید؛ Spatie Activitylog را دوباره منتشر نکنید. سپس `php artisan test --filter=IntlBillingTest` و آزمون‌های Service Requests و Audit را اجرا کنید.
3. `php artisan test --filter=IntlPhase12ReferenceFlowTest` و سپس کل `php artisan test`.
4. همان تست‌ها را روی DB مشابه مقصد تکرار کنید؛ lock/race دو پردازش، Crash بین commit و درخواست مرکزی، اختلاف نتیجه استعلام، و orphan file را جداگانه بیازمایید.
5. سناریو را برای استقرار دوم در **محیط مستقل** با کانفیگ/DB مجزا تکرار کنید؛ ادعای تست استقلال زیرساخت صرفاً با Sandbox واحد پذیرفتنی نیست.

## معیارهای توقف

- هر مورد دسترسی بدون Principal معتبر، نشت داده بین نمایندگی‌ها، انتقال بدون Guard، برداشت دوم در وضعیت نامشخص، یا نشتی داده مشتری/توکن در Audit باعث توقف است.
- هیچ نتیجه `passed` برای آزمون‌های Laravel یا دو پردازش واقعی تا زمان ارائه خروجی اجرایی ثبت نشود.
- برای پرداخت عملیاتی: سند API مرکزی شامل واحد پول، idempotency و استعلام **با همان کلید** ضروری است.
