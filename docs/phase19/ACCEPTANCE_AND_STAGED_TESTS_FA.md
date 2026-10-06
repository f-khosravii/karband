# فاز ۱۹ — ماتریس پذیرش و بسته اجرای مرحله‌ای آینده

این ماتریس **برنامه اجرا** است؛ موارد «اجرا در آینده» به هیچ‌وجه با تست موفق اشتباه گرفته نشوند. تنها شواهد فعلی: بررسی ایستا و Smokeهای PHP مستقل این بسته.

| Gate | سناریو/هدف | معیار قبولی | وضعیت فعلی |
|---|---|---|---|
| G0 | بازپخش پچ فاز ۱۹ روی بازسازی فاز ۱۷، Lint و نبود حلقه Composer | `git apply --check`، Lint و گراف PASS | بررسی در محیط آرشیوی |
| G1 | نصب Composer و Migration افزایشی در Sandbox واقعی | Composer Lock معتبر، Schema بدون collision | اجرا در آینده |
| G2 | هسته Catalog و Recipients، Laravel Finance Bridge | PHPUnit هر هسته و `IntlPhase19CatalogFinancialTermsTest` سبز | اجرا در آینده؛ Smoke مستقل موفق |
| G3-A | پرداخت پس از تأیید | مالکیت و وضعیت معتبر؛ مبلغ قابل تأیید؛ پرداخت موفق | نیازمند تست مرجع پس از نصب |
| G3-B | پرداخت قبل از بررسی | Workflow آن خدمت اجازه پرداخت بدهد، بدون دست‌کاری guard؛ رد سازمان بعد پرداخت | نیازمند سناریوی Reference Host جداگانه |
| G3-C | لغو پیش از تأیید و استرداد کامل | سیاست فریز‌شده پرداخت موفق؛ refund=کل مبلغ؛ هیچ refund دوم | نیازمند سناریوی مرجع و Fake Gateway |
| G3-D | قیمت جدید فقط پس از شکست **قطعی بدون برداشت** | Attempt اول تغییر نکند، Attempt دوم Price/Policy روز داشته باشد | باید تست Laravel واقعی انجام شود |
| G3-E | Timeout یا نتیجه نامشخص | هیچ Charge/Refund جدید؛ فقط Lookup با همان RequestKey | باید تست Laravel و Contract Provider انجام شود |
| G4-A | نماینده حقوقی: دستی/خارجی/ترکیبی | یک نماینده در درخواست؛ شواهد معتبر و بازه اعتبار | Policy Smoke انجام‌شده، Host بیرونی متصل نیست |
| G4-B | قطعی سامانه نماینده | Pending؛ Fallback صرفاً با مجوز معتبر ادمین و شواهد ثبت‌شده | Policy Smoke انجام‌شده، API و Permission واقعی باز است |
| G4-C | چندنمایندگی و مجوزها | هیچ Agency نتواند پروفایل مشتری/درخواست Agency دیگر را بخواند | فقط تست پس از نصب |
| G5 | رقابت در دیتابیس مقصد | تأیید/لغو هم‌زمان، دو پرداخت، تغییر شناسه/ایجاد درخواست | اجرا در MySQL/PostgreSQL واقعی آینده |
| G6 | پیشخوان رسمی | قرارداد و Contract Tests Idempotency/Lookup/Full Refund امضاشده | مسدود تا دریافت API رسمی |
| G7 | انطباق عملیاتی | کلیدگذاری مستقل، Audit بدون PII، فایل‌اسکنر، Backup/Retention | نیازمند تصمیم و محیط میزبان |
| G8 | انتشار | نسخه‌های قفل‌شده، CI، مهاجرت تدریجی، Rollback تأییدشده | اجرا در آینده |

## ترتیب اعمال پچ‌ها روی Sandbox **آینده** (به‌صورت چک‌لیست)

- [ ] Snapshot از پروژه فعلی شامل فایل‌های Untracked/Modified و نسخه‌های Lockfile؛ از `git clean` یا `reset --hard` خودداری کن.
- [ ] Regression روی مبنای نصب‌شده تا Documents.
- [ ] Phase 8 Billing اصلی → Phase 14 Billing اصلاحی؛ Core + Feature.
- [ ] Phase 9 Service Requests اصلی → Phase 10 Audit → Phase 13 Service Recipients → Phase 15 Service Requests اصلاحی؛ Core + Feature.
- [ ] Phase 12 Reference Host اولیه → Phase 17 Reference Host v2؛ Regression.
- [ ] Phase 19 Bridge حاضر؛ فقط پس از انجام گام‌های پیشین. `require_atomic_financial_terms=true` در تنظیمات میزبان واقعی الزام است؛ در آزمون میزبان، آن را عمداً تنظیم و عدم Binding را نیز تست کن.
- [ ] در هر مرحله Composer و Migration روی دیتابیس آزمایشی مستقل، نه Production/NCR.
- [ ] پس از سبز شدن Sandbox، سناریوهای G3-B و G3-C را با Fake جداگانه پوشش بده؛ موفقیت آن‌ها جایگزین G6 نیست.
- [ ] بعد از دریافت قرارداد پیشخوان و سامانه نماینده، Adapterهای واقعی را جداگانه پیاده کن و Contract Test بگیر.

**نکته:** پچ فاز ۱۹ کارآمدی و صحت گاردهای بیرونی را جایگزین نمی‌کند. اتصال‌های قابل استفاده در محصول باید با ورودی معتبر سرور و خطای Fail-Closed روی میزبان واقعی تست شوند.
