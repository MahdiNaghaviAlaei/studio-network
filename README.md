# Filament Network Studio

**اجرای آفلاین با Docker و `docker load`:** [راهنمای Docker](docker/README.md). بسته شامل یک کانتینر و SQLite است و روی سیستم مقصد به PHP یا Node.js نیاز ندارد. برای ساخت فایل انتقال از `export-docker.ps1` استفاده کنید.

نمونه مدیریت و ترسیم توپولوژی شبکه در **Laravel 12 و Filament 3**، مناسب اجرای محلی و ارائه.
بوم با Alpine و Cytoscape.js در همین پروژه اجرا می‌شود. نصب به Composer و npm و اینترنت یا مخزن بسته‌های سازمان نیاز دارد؛ اجرای پنل فقط PHP و دیتابیس می‌خواهد.

## نصب روی سیستم تازه ـ Windows

پیش‌نیازها: **Git، PHP 8.2+، Composer 2 و Node.js 22** در PATH.
افزونه‌های PHP: `intl`, `mbstring`, `pdo_sqlite`, `curl`, `openssl`, `fileinfo`, `dom`, `xml`.
با `php -v` و `php --ini` نسخه و فایل تنظیمات فعال را ببینید.

در PowerShell:

```powershell
git clone https://github.com/MahdiNaghaviAlaei/studio-network.git
cd studio-network
powershell -ExecutionPolicy Bypass -File .\setup-network.ps1 -Demo -CreateAdmin
powershell -ExecutionPolicy Bypass -File .\start.ps1
```

اسکریپت نصب `.env`، کلید اختصاصی برنامه و دیتابیس SQLite را می‌سازد؛ وابستگی‌ها را نصب و بوم را build می‌کند، migrationها و نقشه نمونه را اجرا می‌کند. سپس نام، ایمیل و رمز حساب مدیر را از شما می‌پرسد. **رمز پیش‌فرض مشترکی وجود ندارد؛ با حساب خودتان وارد شوید.**

پنل: **http://127.0.0.1:8000/admin**

نقشه آماده در «مدیریت شبکه»: **«توپولوژی نمونه | دو رک و اتصال پورت‌ها»**. شامل دو رک ۱۲ یونیت، روتر، فایروال، دو سوییچ، سرور و کامپیوتر و پنج اتصال با نام پورت‌ها است. سرور در U3–U4 رک B قرار دارد. تجهیزات و آدرس‌ها نمایشی‌اند.

اجرای دوباره نصب، کلید را عوض نمی‌کند و دیتابیس یا نقشه نمونه ویرایش‌شده را بازنشانی نمی‌کند. برای نصب بدون داده نمونه، `-Demo` را حذف کنید. برای ساخت حساب بعداً:

```powershell
php artisan make:filament-user
```

اگر پورت ۸۰۰۰ اشغال بود:

```powershell
powershell -ExecutionPolicy Bypass -File .\start.ps1 -Port 8001
```

## نصب دستی یا Linux / macOS

```sh
php scripts/prepare-environment.php
composer install --prefer-dist --no-interaction
npm ci --no-audit --no-fund
npm run build
php artisan config:clear
php artisan migrate --force
php artisan db:seed --class=NetworkDemoSeeder --force
php artisan make:filament-user
php artisan serve --host=127.0.0.1 --port=8000
```

در Windows در صورت مسدود بودن اجرای `npm` در PowerShell، `npm.cmd` را استفاده کنید. پوشه‌های `storage` و `bootstrap/cache` باید برای PHP قابل نوشتن باشند. پیش‌فرض‌ها برای ارائه محلی با `APP_ENV=local` و SQLite هستند؛ این دستورها راهنمای استقرار عمومی یا اتصال به دیتابیس اصلی شرکت نیستند.

## امکانات و حدود نسخه

نقشه مستقل هر شبکه، فهرست تجهیزات مشترک، رک و یونیت، گروه‌بندی، اتصال با نام پورت، جستجو، چیدمان خودکار، تم، ذخیره و تاریخچه و خروجی PNG/SVG/JSON/YAML/Markdown پیاده شده‌اند.

وضعیت‌ها دستی‌اند. اسکن، Ping، کشف خودکار کابل، کنترل اشغال پورت و اجرای عملیات SOAR پیاده نشده‌اند. این نمونه با الهام از [Homelable](https://github.com/Pouzor/homelable) ساخته شده و بازسازی کامل آن نیست. برای اجرا به Python، Homelable، n8n یا CDN نیازی ندارد.

## به‌روزرسانی و بررسی

قبل از به‌روزرسانی از `.env` و `database/database.sqlite` پشتیبان بگیرید، سپس:

```powershell
git pull --ff-only
powershell -ExecutionPolicy Bypass -File .\setup-network.ps1
php artisan test
```

تست مرورگر اختیاری است و Chrome و Playwright می‌خواهد:

```powershell
npm.cmd install --prefix storage/browser-tools playwright --no-audit --no-fund
$env:BROWSER_EMAIL = 'your-local-admin-email'
$env:BROWSER_PASSWORD = 'your-local-admin-password'
node tests/Browser/native-smoke.cjs
```

نشانی تست با `BROWSER_BASE_URL`، مسیر Chrome با `CHROME_PATH` و مسیر PHP با `PHP_BINARY` قابل تنظیم است.

[راهنمای امکانات و معماری](docs/NETWORK-MANAGEMENT.md)

فایل‌های `.env`، دیتابیس، گزارش‌ها، داده‌های محلی و پوشه‌های `vendor`، `node_modules` و `reference` در Git قرار نمی‌گیرند. نقشه نمونه با Seeder بازسازی می‌شود. دیتابیس این محیط برای نصب تازه لازم نیست.
