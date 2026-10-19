# اجرای ساده و آفلاین با Docker

یک کانتینر شامل Apache، PHP و SQLite است. فقط Docker با حالت **Linux containers** روی سیستم مقصد لازم است؛ بعد از انتقال image به Git، PHP، Node.js، Composer یا اینترنت نیاز ندارید. بسته آماده برای `linux/amd64` است؛ مناسب سیستم‌های معمول Intel/AMD.

## روش آسان در Windows

کل پوشه بسته آفلاین، شامل `studio-network.tar` و `run.ps1` را منتقل کنید. Docker Desktop را روشن کنید و داخل همان پوشه PowerShell باز کنید:

```powershell
powershell -ExecutionPolicy Bypass -File .\run.ps1
```

اسکریپت image را load می‌کند، کانتینر را بالا می‌آورد و در اولین اجرا نام، ایمیل و رمز مدیر را می‌پرسد. سپس http://localhost:8000/admin را باز کنید. رمز آماده و مشترکی داخل image نیست.

اگر پورت ۸۰۰۰ اشغال است، در اولین اجرا:

```powershell
powershell -ExecutionPolicy Bypass -File .\run.ps1 -Port 8080
```

## روش دستی، Windows یا Linux

```sh
docker load -i studio-network.tar
docker run -d --pull=never --name studio-network --restart unless-stopped -p 127.0.0.1:8000:80 -v studio-network-data:/data studio-network:demo
docker exec -it --user www-data studio-network php artisan make:filament-user
```

دستور آخر فقط برای ساخت حساب اول لازم است. نقشه دو رک به‌صورت خودکار آماده می‌شود. از حسابی که ساخته‌اید وارد پنل شوید.

خاموش و روشن کردن بعدی:

```sh
docker stop studio-network
docker start studio-network
```

دیتابیس و کلید برنامه در volume با نام `studio-network-data` باقی می‌مانند. این volume شامل اطلاعات ایجادشده روی همان سیستم است؛ فایل `docker save` فقط image برنامه و نقشه نمونه را منتقل می‌کند، نه داده‌های volume یا دیتابیس شخصی سیستم مبدا.

بررسی وضعیت و خطا:

```sh
docker ps
docker logs --tail 50 studio-network
```

## ساخت بسته از سورس

ساخت image به اینترنت یا registry و مخزن بسته‌های در دسترس نیاز دارد. داخل ریشه پروژه:

```powershell
powershell -ExecutionPolicy Bypass -File .\export-docker.ps1
```

خروجی در `artifacts/studio-network-docker` قرار می‌گیرد. این پوشه را به شرکت منتقل کنید. فایل tar در GitHub قرار نمی‌گیرد؛ Dockerfile و اسکریپت‌های ساخت داخل مخزن هستند.

این بسته برای ارائه محلی است. وضعیت تجهیزات دستی و حساب‌های پنل در فضای مشترک‌اند. اسکن، پایش واقعی و کنترل اشغال پورت هنوز جزو این نمونه نیستند.
