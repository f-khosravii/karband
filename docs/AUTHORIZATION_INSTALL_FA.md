# نصب INTL Authorization — فاز پکیج مشترک

این پچ فقط فایل‌های **جدید** می‌افزاید: قراردادهای Authorization در `intl/contracts`، پکیج `intl/authorization-laravel` و تست‌های Sandbox. هیچ کد، جدول یا Route مربوط به NCR را تغییر نمی‌دهد؛ Bridge به Workflow نیز تا زمان انتخاب و ثبت توسط میزبان فعال نیست.

## ۱. اعمال Patch در ریشه Monorepo

```bash
cd ~/Desktop/intl-platform
git status --short
git apply --check /path/to/intl-authorization.patch
git apply /path/to/intl-authorization.patch
```

اگر Git اعلام کرد فایل موجود است، Patch را دوباره اجرا نکن و وضعیت فایل‌ها را بررسی کن. به‌جای Patch می‌توان ZIP را در ریشه پروژه استخراج کرد؛ **هر دو را با هم استفاده نکن**.

## ۲. نصب فقط در Sandbox

از قبل `intl/contracts`, `intl/workflow`, `intl/workflow-laravel` در Sandbox نصب شده‌اند. برخلاف پکیج Core، این Adapter را به Composer ریشه پروژه اضافه نکن: به Laravel نیاز دارد.

```bash
cd ~/Desktop/intl-platform/apps/sandbox
composer config repositories.intl-local path '../../packages/*'
composer require 'intl/authorization-laravel:dev-main' -W
php artisan package:discover
```

وابستگی `spatie/laravel-permission:^8.0` باید با Composer نصب شود. قبل از انتشار Migration بررسی کن آیا اپ قبلاً Spatie داشته است:

```bash
ls config/permission.php
ls database/migrations/*permission*
```

اگر Spatie قبلاً نصب و پیکربندی شده است، Migration تکراری منتشر نکن. اگر نصب تازه است، فقط در Sandbox:

```bash
php artisan vendor:publish --provider='Spatie\Permission\PermissionServiceProvider'
php artisan optimize:clear
php artisan migrate:status
```

پیش از هر Migration از ایزوله‌بودن دیتابیس Sandbox اطمینان پیدا کن؛ در حالت تست `DB_CONNECTION=sqlite` و `DB_DATABASE=:memory:` مناسب است. اگر Migration تازه منتشرشده روی دیتابیس محلی Sandbox اعمال نشده و آن دیتابیس کاملاً آزمایشی است، ابتدا `php artisan migrate --pretend` و بعد `php artisan migrate` را اجرا کن. روی دیتابیس NCR، shared یا production هیچ Migrationای اجرا نکن.

در مدل احرازهویت **میزبان واقعی** که قرار است Spatie را استفاده کند، trait `Spatie\Permission\Traits\HasRoles` باید وجود داشته باشد. تست‌های پیوست از مدل تستی مجزا استفاده می‌کنند و فایل `App\Models\User` فعلی Sandbox را بازنویسی نمی‌کنند. با SSO چند guard، نقش و permission را با `guard_name` درست (مثلاً `web` یا guard خارجی واقعی) ایجاد کن؛ Spatie برای هر guard نام‌فضای نقش/مجوز جدا دارد.

## ۳. اتصال سرویس میزبان (الزامی و تعمداً خارج از Patch)

هر میکروسرویس باید دو قرارداد را به پیاده‌سازی مورد اعتماد خودش متصل کند:

- `Intl\AuthorizationLaravel\Contracts\PrincipalProvider`: کاربر و guard فعلی، نقش انتخاب‌شده و شناسه نمایندگی/سازمان را از session/SSO و دیتابیس امن برمی‌گرداند؛ مقدار نقش و organizationId ورودی کلاینت نیست.
- `Intl\AuthorizationLaravel\Contracts\ScopeChecker`: برای هر `resourceType` فقط از Repository/Policy میزبان دسترسی را ارزیابی می‌کند. برای نمایندگی مالکیت درخواست (`request.agency_id === actor.organizationId`) الزامی است. ادمین سازمان در **همان سرویس اختصاصی خودش** نیازی به فیلتر بین‌سازمانی ندارد، ولی حق اجرای عملیات همچنان باید در Spatie تعریف شده باشد. برای ادمین پلتفرم هم هیچ super-admin خودکاری نداریم.

نمونه محل ثبت در Service Provider خود اپ، **بعد از نوشتن کلاس‌های اختصاصی آن اپ**:

```php
use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\AuthorizationLaravel\Contracts\ScopeChecker;

$this->app->bind(PrincipalProvider::class, App\Authorization\SsoPrincipalProvider::class);
$this->app->bind(ScopeChecker::class, App\Authorization\ServiceResourceScopeChecker::class);
```

نام دو کلاس `App\Authorization\...` صرفاً مثال است؛ **در پچ وجود ندارند** و باید با احرازهویت و مدل داده واقعی هر میزبان پیاده شوند. تا زمان ثبت واقعی، به هیچ Routeای دسترسی از این پکیج نده. پیاده‌سازی `ScopeChecker` باید نوع منبع ناشناخته یا رکورد حذف‌شده را رد کند؛ هنگام توسعه می‌توان از Laravel Policy در آن استفاده کرد.

اگر اپ می‌خواهد از Bridge آماده Workflow استفاده کند، این دو Binding هم در Provider **خود اپ** ثبت می‌شوند (اختیاری؛ نه بخشی از نصب Sandbox):

```php
use Intl\WorkflowLaravel\Contracts\ActorResolver;
use Intl\WorkflowLaravel\Contracts\WorkflowAuthorizer;
use Intl\AuthorizationLaravel\Bridge\WorkflowActorResolver;
use Intl\AuthorizationLaravel\Bridge\WorkflowAuthorizationBridge;

$this->app->bind(ActorResolver::class, WorkflowActorResolver::class);
$this->app->bind(WorkflowAuthorizer::class, WorkflowAuthorizationBridge::class);
```

این Bridge مجوزها را به صورت `workflow.definition.publish`، `workflow.instance.start`، `workflow.instance.view` و `workflow.action.<action>` به Spatie می‌سپارد. برای نمونه، `agency` می‌تواند مجوز `workflow.action.submit` داشته باشد، `government_admin` مجوز `workflow.action.require_correction` و `platform_admin` مجوز انتشار تعریف؛ **فقط پس از آنکه میزبان خودش این مجوزها را در Guard واقعی ایجاد و واگذار کرده باشد**.

⚠️ تا وقتی Adapter به اپ واقعی وصل نشده است، تست‌های Sandbox از Fake PrincipalProvider و Fake ScopeChecker استفاده می‌کنند. Fakeها نباید در production استفاده شوند. در صورت وجود چند Guard، تعیین Guard باید همیشه از سیستم احرازهویت واقعی صورت بگیرد، نه از درخواست HTTP.

## ۴. تست‌های Sandbox

بعد از نصب و انتشار Migrationهای Spatie **فقط در Sandbox**:

```bash
php artisan test --filter=IntlAuthorizationAdapterTest
php artisan test
cd ../..
./vendor/bin/phpunit
```

تست‌های پیوست ۱۳ سناریوی Laravel را پوشش می‌دهند. تا زمانی که پکیج و Migrationهای Spatie در Sandbox نصب نشده‌اند، افزودن فایل تست به‌تنهایی موجب خطا خواهد شد. اجرای موفق تست‌ها در Sandbox به‌معنای تأیید امنیت NCR نیست. قبل از فعال‌سازی عملیاتی، mapping نقش‌های SSO، مالکیت درخواست، جزئیات Guard و Policyهای واقعی NCR باید جداگانه آزمایش شوند.

اگر می‌خواهی تست‌های smoke مستقل از Laravel را هم بررسی کنی:

```bash
cd ~/Desktop/intl-platform
php packages/authorization-laravel/tests/contract-smoke.php
php packages/authorization-laravel/tests/standalone-smoke.php
```

Smoke دوم از stubهای حداقلی Spatie/Laravel استفاده می‌کند و جایگزین تست‌های یکپارچگی Laravel نیست.

## ۵. محدوده و برگشت

پکیج Migration و Seeder خودکار ندارد؛ جدول‌های نقش/مجوز صرفاً متعلق به Spatie هستند و انتشار آن‌ها تصمیم میزبان است. حذف پکیج نیازمند پاک‌کردن خودسرانه جدول‌های Spatie نیست، زیرا اپ ممکن است از همان جدول‌ها برای موارد دیگر استفاده کند. برای برگرداندن تغییر در Monorepo، در صورت ثبت‌نشدن تغییرات مستقل دیگر، از روش استاندارد Git استفاده کن و `composer.json`/`composer.lock` تغییرکرده در Sandbox را جداگانه بررسی کن.

برای بررسی قرارداد سازگاری با Workflow قبلی (بدون Laravel) می‌توانی تست سوم را هم اجرا کنی:

```bash
php packages/authorization-laravel/tests/bridge-smoke.php
```
