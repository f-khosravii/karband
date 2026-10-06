# نصب فاز هفتم: INTL Document Management

## معماری و مرزها

- دو پکیج: `intl/documents` مستقل از فریم‌ورک، و `intl/documents-laravel` برای ذخیره و کنترل دسترسی لاراول.
- هر مایکروسرویس سازمان دولتی دیتابیس، دیسک خصوصی و استقرار **مستقل** خودش را دارد؛ هیچ API یا دیتابیس مرکزی وجود ندارد.
- برای هر خدمت فهرست `DocumentRequirement` شامل کد، عنوان، اجباری/اختیاری، MIMEهای مجاز و سقف حجم تعریف می‌شود؛ دسته‌بندی نداریم. هر کد فعلاً فقط **یک فایل جاری** دارد. برای چند مدرک، کدهای جدا تعریف کنید.
- ادمین مجاز پیش‌نویس را از طریق API داخلی `saveDraft` و `publishDraft` مدیریت می‌کند؛ نسخه‌های منتشرشده تغییرناپذیرند. پنل گرافیکی و APIهای HTTP جزو بسته نیستند.
- نمایندگی `reserve` را هنگام ایجاد درخواست فراخوانی می‌کند تا نسخه مدارک ثابت شود. پس از آن `upload()` فایل‌های هر slot را با Revision اجباری ثبت می‌کند. فایل جایگزین حذف فیزیکی فایل قبلی نیست؛ تاریخچه قابل حسابرسی حفظ می‌شود.
- جایگزینی فایل قبلی تنها وقتی مجاز است که `ReplacementPolicy` سفارشی اپلیکیشن بر اساس **وضعیت واقعی Workflow از دیتابیس** مجوز بدهد. موتور هیچ وضعیت/نقشی را به همه سرویس‌ها تحمیل نمی‌کند.
- تکمیل مدارک با `TrustedDocumentFacts::isComplete()` به‌صورت فقط-خواندنی به `TrustedFactProvider` مایکروسرویس متصل می‌شود. داده درخواست HTTP، پاسخ فرم یا فیلد `status` فرستاده‌شده از سمت کاربر منبع معتبر تشخیص اصلاح نیست.

## نصب در INTL Platform، بدون دست‌زدن به NCR

۱. یکی از روش‌های ZIP یا Patch را انتخاب کنید، هر دو را باهم اجرا نکنید. Patch فقط فایل جدید اضافه می‌کند. ابتدا در ریشه پروژه:

```bash
cd ~/Desktop/intl-platform
git apply --check intl-documents.patch
git apply intl-documents.patch
composer require 'intl/documents:dev-main' -W
./vendor/bin/phpunit -c packages/documents/phpunit.xml
```

۲. داخل Sandbox؛ دو پکیج را صریح نصب کنید تا خطای Composer minimum-stability رخ ندهد:

```bash
cd apps/sandbox
composer config repositories.intl-local path '../../packages/*'
composer require 'intl/documents:dev-main' 'intl/documents-laravel:dev-main' -W
php artisan package:discover
php artisan optimize:clear
php artisan test --filter=IntlDocumentsTest
php artisan test
```

اگر برای اولین بار Migrationها را می‌خواهید روی یک Sandbox اختصاصی اجرا کنید، ابتدا دیتابیس تست را بررسی کنید؛ تست‌های `RefreshDatabase` به طور خودکار Migrationهای منتشرشده توسط Service Provider را در `sqlite :memory:` اعمال می‌کنند. **روی دیتابیس NCR یا Production اجرای Migration انجام ندهید.**

۳. برای افزودن تست‌های مستقل به ریشه، داخل `<testsuites>` فایل `intl-platform/phpunit.xml` این سطر را اضافه کنید:

```xml
<testsuite name="Documents"><directory>packages/documents/tests</directory></testsuite>
```

سپس `./vendor/bin/phpunit` را از ریشه اجرا کنید. فایل Patch به علت جلوگیری از بازنویسی فایل ریشه، `composer.json` و `phpunit.xml` فعلی را تغییر نمی‌دهد.

## تنظیمات امنیتی لازم در هر مایکروسرویس

- به‌صورت صریح از دیسک خصوصی Laravel استفاده کنید؛ پیش‌فرض `local` در Laravel 13 معمولاً در `storage/app/private` است؛ مسیر واقعی را قبل از استقرار بررسی کنید. `public`، `visibility=public` و قرارگرفتن زیر `public_path()` مردودند.
- `config/intl-documents.php` شامل `connection`، `private_disk`، MIME allowlist و سقف حجم است. در صورت تفاوت کانکشن، `intl-documents.connection` و `intl-service-catalog.connection` باید **به یک Connection لاراول** اشاره کنند.
- هیچ نقش یا Permission خودکار اعطا نمی‌شود. مجوزها: `documents.definition.edit`, `documents.definition.view`, `documents.definition.publish`, `documents.file.upload`, `documents.file.view`, و `catalog.service.use`. در نمونه تست ادمین پلتفرم پیش‌نویس را مدیریت می‌کند، نمایندگی فایل بارگذاری می‌کند و ادمین سازمان می‌خواند.
- در میزبان `PrincipalProvider` و `ScopeChecker` پکیج Authorization را به گارد واقعی و بررسی مالکیت واقعی متصل کنید؛ هیچ مقدار ارسالی از HTTP برای شناسه/نقش/نمایندگی معتبر نیست.
- **الزامی:** `ResourceVerifier::existsForService()` با کوئری مطمئن روی منبع واقعی اپلیکیشن، وجود درخواست و تعلق آن به خدمت را کنترل کند؛ Binding پیش‌فرض وجود ندارد.
- **الزامی:** `FileSafetyScanner::assertSafe()` باید به اسکنر واقعی و قابل دسترس هر سازمان متصل شود و در وضعیت خطا استثنا بدهد؛ هیچ پیاده‌سازی پیش‌فرض allow-all وجود ندارد. استاب allow-all موجود در تست صرفاً برای فایل‌های ساختگی Sandbox است.
- **الزامی:** `ReplacementPolicy::allows()` وضعیت واقعی Workflow و Permission و مالکیت را بررسی کند؛ برای مثال صرفاً هنگامی‌که `correction_required` و پرونده هنوز باز است. در حالت خطا یا فقدان Policy اجازه جایگزینی داده نمی‌شود.
- `DocumentManager::open()` فقط یک Stream خصوصی بعد از Authorization برمی‌گرداند. کنترلر میزبان باید آن را با HTTP response مناسب، `Content-Disposition: attachment` و escape درست نام فایل عرضه کند؛ مستقیماً مسیر فایل و URL استوریج را ارسال نکند.

## الگوی اتصال به Workflow و Dynamic Forms

هنگام ایجاد پرونده، میزبان در تراکنش دیتابیس یکسان، فرم (`FormAnswers::reserve`)، مدارک (`DocumentManager::reserve`) و Workflow (`CatalogWorkflowStarter::start`) را در ترتیبی متناسب با ایجاد منبع معتبر ثبت کند. `ResourceVerifier` باید منبعِ ایجادشده را مشاهده کند. خدمات بدون مدارک هم یک `DocumentDefinition` با آرایه خالی منتشر می‌کنند؛ در این صورت یک set رزرو‌شده کامل محسوب می‌شود.

برای فعال‌کردن شرط مدارک، میزبان `Intl\WorkflowLaravel\Contracts\TrustedFactProvider` را پیاده کند، `TrustedDocumentFacts::isComplete(serviceCode, resourceType, resourceId)` را از بک‌اند بخواند و مقدار bool را به فکت `documents_complete` تبدیل کند. خود Workflow در تعریف سرویس شرط/Guard مربوط به این فکت را تعیین می‌کند. این بسته به‌صورت پیش‌فرض مانع Submit نمی‌شود تا قانون هر سرویس اختیاری باقی بماند.

**سیاست اصلاح:** Action `require_correction` در Workflow باید قبل از فعال‌شدن Policy جایگزینی کامل و ثبت شده باشد. خود بسته سیاست پیش‌فرضی ندارد و وضعیت را از ورودی کاربر نمی‌گیرد. در فرم مستقل Dynamic Forms، اصلاح پاسخ‌ها سیاست جداگانه آن پکیج را دارد.

## محدودیت نسخه فعلی و قبل از استفاده عملیاتی

- هوک اسکن الزامی وجود دارد، اما **پیاده‌سازی اسکنر واقعی**، قرنطینه، رمزگذاری اختصاصی، سیاست حذف/Retention مطابق قانون سازمان، سازگارسازی با Object Storage خاص، بررسی حذف فایل در رخدادهای crash، OCR، امضای دیجیتال یا API/پنل آماده نیست. این موارد برای مدارک هویتی واقعی لازم است مطابق الزامات امنیتی هر سازمان طراحی شوند.
- `DB::transaction` عملیات DB را اتمی می‌کند، اما آپلود blob در Filesystem با DB تراکنش سراسری ندارد؛ در rollback تلاش به حذف blob تازه انجام می‌شود. برای سناریوی crash یا Storage غیراتمی، Reconciliation دوره‌ای الزامی است.
- Revision مانع overwrite از رابط کاربری stale می‌شود؛ تست race واقعی هم‌زمان در پایگاه‌های مورد استفاده هر سازمان پیش از Production لازم است.
- تاریخچه و blob جایگزین‌های قدیمی حذف نمی‌شوند؛ پیش از Production نگهداری، دسترسی، رمزگذاری، پشتیبان‌گیری و فرایند امن امحا را تعیین کنید.
- اتصال واقعی NCR و Deployment مستقل سازمان‌ها همچنان برای مرحله بعد باقی است.
