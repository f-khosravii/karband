# ماتریس پذیرش و چک‌لیست آزمون فاز نهایی

هر خانه «قبول» تنها با گزارش تازه همان نصب در **Sandbox** علامت می‌خورد، نه صرفاً با Smoke یا خروجی قدیمی. برای Release Candidate، تست‌های چندفرایندی و DB مقصد نیز ضروری‌اند.

| لایه | آزمون ضروری | قبلاً گزارش شده؟ | آزمون نهایی |
|---|---|---|---|
| Root / Contracts | `./vendor/bin/phpunit` و تست تمام قراردادهای اضافی | ۴۵ تست/۸۲ assertion بعد از نصب Workflow (قدیمی) | ☐ |
| Workflow Core | `-c packages/workflow/phpunit.xml` | ۴۱ تست در مرحله قبلی | ☐ |
| Workflow Laravel | `--filter=IntlWorkflowAdapterTest` | ۱۹ تست در مرحله قبلی | ☐ |
| Authorization Laravel | `--filter=IntlAuthorizationAdapterTest` | ۱۳ تست در مرحله قبلی | ☐ |
| Service Catalog Core | `-c packages/service-catalog/phpunit.xml` | گزارش موفقیت مستقل جدید در این بررسی موجود نیست | ☐ |
| Service Catalog Laravel | `--filter=IntlServiceCatalogTest` | ۲۴ تست در مرحله قبلی | ☐ |
| Dynamic Forms Core | `-c packages/dynamic-forms/phpunit.xml` | Smoke ایستا فعلی موفق؛ خروجی PHPUnit مستقل در گفتگو ثبت نشده | ☐ |
| Dynamic Forms Laravel | `--filter=IntlDynamicFormsTest` | ۸۲ تست کامل Sandbox تا آن مرحله | ☐ |
| Documents Core | `-c packages/documents/phpunit.xml` | ۱۱ تست/۱۴ assertion | ☐ |
| Documents Laravel | `--filter=IntlDocumentsTest` | ۱۱۱ تست کامل Sandbox پس از اصلاح fixture | ☐ |
| Billing Core | `-c packages/billing/phpunit.xml` | فقط Smoke محلی؛ تست PHPUnit واقعی انجام نشده | ☐ |
| Billing Laravel | `--filter=IntlBillingTest` | هنوز نصب نشده | ☐ |
| Service Requests Core | `-c packages/service-requests/phpunit.xml` | فقط Smoke محلی؛ تست PHPUnit واقعی انجام نشده | ☐ |
| Service Requests Laravel | `--filter=IntlServiceRequestsTest` | هنوز نصب نشده | ☐ |
| Audit Contracts | `packages/contracts/tests/AuditEventTest.php` | Smoke محلی پنج بررسی | ☐ |
| Audit Laravel | `--filter=IntlAuditTest` | هنوز نصب نشده | ☐ |
| کل Sandbox | `php artisan test` | ۱۱۱ تست/۲۰۳ assertion فقط تا Documents | ☐ |
| کل Package Check | `php tools/verify-platform-release.php --complete` | روی تحویل ایستای مصنوعی تأیید می‌شود؛ مخزن واقعی هنوز بررسی نشده | ☐ |

## آزمون‌های دستی / چندفرایندی ضروری

- ☐ نمایندگی فقط پرونده‌ها و خدمات مجاز خودش را می‌بیند؛ با تغییر ID، Role یا Guard از طریق کلاینت امکان ارتقای دسترسی ندارد.
- ☐ Government Admin در همان سرویس سازمانی، بدون فیلتر سازمانی مصنوعی، فقط اقدامات مجاز را انجام می‌دهد؛ Platform Admin امتیاز ضمنی ندارد.
- ☐ درخواست جدید تمام Pinهای Workflow/Form/Documents را در همان تراکنش محلی می‌گیرد؛ شکست در هر رزرو هیچ رکورد نیمه‌کاره باقی نمی‌گذارد.
- ☐ یک خدمت غیرفعال ایجاد درخواست جدید را مسدود می‌کند، اما نسخه‌های Pinشده درخواست‌های موجود را تخریب نمی‌کند.
- ☐ ویرایش هم‌زمان، نسخه stale و دو فراخوانی یک idempotency key رفتار تعیین‌شده دارند؛ قفل روی MySQL/PostgreSQL نیز آزمایش شده است.
- ☐ فایل ناامن رد می‌شود، از storage خصوصی URL عمومی تولید نمی‌شود، فایل‌های orphan پس از شکست ذخیره‌سازی طبق سیاست میزبان جمع‌آوری می‌شوند.
- ☐ برای پرداخت از Fake، پاسخ نامشخص، قطع اتصال هنگام charge، نتیجه دیررس و استعلام قطعی را شبیه‌سازی کنید؛ در هیچ مسیر مبهمی charge تازه با کلید متفاوت صادر نشود.
- ☐ تعریف رسمی واحد پول، کلید پایدار سراسری و استعلام API واقعی پیشخوان قبل از فعال‌سازی تولیدی موجود است.
- ☐ Audit تنها رویدادهای allowlisted با شناسه opaque ثبت کند؛ Password/Token/پیام API/مدرک مشتری وارد Activitylog نشود.
- ☐ رخداد Audit موفق محلی در همان Connection و Transaction ثبت شود؛ Rollback هر دو را برگرداند؛ رخداد ردشده پس از Rollback بر اساس Principal معتبر به‌طور جداگانه ثبت شود.
- ☐ Migrationهای Spatie برای نوع ID کاربر، uniqueها و ترتیب schema با دیتابیس مقصد بررسی شده‌اند.
- ☐ هر مایکروسرویس بتواند مستقل از سایر سازمان‌ها با نسخه پکیج ثبت‌شده در lockfile Deploy و Rollback شود.
- ☐ پس از همه آزمون‌ها، تطبیق NCR موجود و مهاجرت درخواست‌های باز در پروژه آزمایشی مجزا ارزیابی شده است.
