# فاز ۱۲ — ماتریس اتصال میزبان

| قرارداد / وابستگی | اطلاعات معتبر ورودی | نمونه فاز ۱۲ | پیاده‌سازی لازم در استقرار واقعی | رفتار هنگام خطا |
|---|---|---|---|---|
| `PrincipalProvider` | Laravel Guard معتبر + نقش انتخاب‌شده و نمایندگی | حساب‌های ساختگی مجزا | SSO/Auth خود مایکروسرویس و تطبیق با Guard واقعی | رد دسترسی |
| `ScopeChecker` | نقش انتخاب‌شده + مالکیت واقعی Request از DB محلی | خواندن `intl_service_requests` | Policy میزبان، مالکیت نمایندگی و مجوز صریح | رد دسترسی |
| `ActorResolver` / `WorkflowAuthorizer` | Principal/Authorizer معتبر | Bridgeهای موجود Authorization | ثبت صریح Binding در هر میزبان | رد اقدام |
| `RecipientVerifier` | شناسه مشتریِ غیرکاربر و تعلق معتبر به نمایندگی | جدول ذهنی ثابتِ صرفاً تستی | داده/سرویس معتبر مشتری در استقرار همان سازمان | رد ایجاد درخواست |
| `TrustedFactProvider` + `TransitionGuard` | پاسخ فرم ذخیره‌شده، مدارک معتبر و تأیید پرداخت | محاسبه از DB/Trusted Facts + Guard | شروط اختصاصی و قابل اتکای هر خدمت | عدم تغییر وضعیت |
| `ResourceVerifier` | شناسه درخواست و خدمت از DB محلی | Binding موجود `ServiceRequestResourceVerifier` | تأیید هویت موجودیت برای انواع منابع دیگر | رد فایل |
| `ReplacementPolicy` | وضعیت جاری از Workflow، نمایندگی واقعی | فقط `correction_required` | سیاست اصلاح هر سازمان | رد جایگزینی |
| `FileSafetyScanner` | بایت‌های موقت و MIME واقعی | فقط تشخیص امضای ساختگی PDF در تست | اسکنر واقعی با مدیریت خطا و quarantine | رد فایل |
| `TrustedQuoteProvider` | مالکیت درخواست، خدمت، وضعیت تأیید‌شده، تعرفه معتبر | قیمت ساختگی ۵۰۰ IRR | تعرفه و قواعد مالی معتبر Backend | رد پرداخت |
| `CentralPaymentGateway` | کلید یکتا و پایدار، شناسه پرونده، مبلغ معتبر | Fake `settleThenTimeout` و `lookup` | فقط پس از اخذ مستندات معتبر API مرکزی | `unknown`، نه debit مجدد |
| `AuditRecorderInterface` | شناسه بازیگر معتبر + رویداد محدود | Spatie Activitylog در DB تست | خط‌مشی رویدادهای حساس + retention و مجوز مشاهده | عدم افشای داده |

**نکته:** وجود `TrustedFactProvider` تستی و `TrustedQuoteProvider` نمونه به معنای آماده بودن قوانین واقعی سازمان نیست. `scope` و `quote`، ورودی درخواست HTTP را مرجع حقیقت در نظر نمی‌گیرند. یک میکروسرویس فقط به DB/شبکه خودش متکی است؛ استثنا، API مرکزی پرداخت است که پشت قرارداد `CentralPaymentGateway` پنهان می‌ماند.
