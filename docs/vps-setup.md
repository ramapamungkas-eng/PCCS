# VPS Setup Guide

Deploy this Laravel 12 + Livewire/Volt + Tailwind CSS application on an Ubuntu server.

## Stack assumptions

- Ubuntu 24.04 LTS
- Apache2 + PHP 8.5-FPM
- SQLite (default) or MySQL/PostgreSQL
- Node.js + npm (required by Puppeteer for PDF/label generation)
- Chromium — Puppeteer downloads its own, or a system Chrome/Chromium can be used
- Composer
- Redis (recommended for cache, session, and queues)
- Cloudflare origin certificate (optional but recommended)

## 1. Server packages

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y apache2 php8.5-fpm php8.5-cli php8.5-sqlite3 php8.5-mysql php8.5-pgsql \
    php8.5-mbstring php8.5-xml php8.5-bcmath php8.5-curl php8.5-zip php8.5-intl \
    php8.5-redis unzip git curl redis-server
```

Install Composer and Node:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

curl -fsSL https://deb.nodesource.com/setup_24.x | sudo -E bash -
sudo apt install -y nodejs
```

## 2. Deploy the code

```bash
cd /var/www
sudo git clone <your-new-remote-url> pccs
cd pccs
sudo usermod -a -G www-data $USER
sudo chown -R $USER:www-data .
sudo chmod -R g+s storage bootstrap/cache
```

**Important:** Log out and back in (or run `newgrp www-data`) for the group addition to take effect.

## 3. Environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` for production:

```env
APP_NAME="PCCS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
APP_TIMEZONE=Asia/Jakarta
APP_LOCALE=id
APP_FALLBACK_LOCALE=en

DB_CONNECTION=sqlite
# For MySQL/PostgreSQL, set DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD

SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_FROM_ADDRESS="noreply@your-domain.com"
MAIL_FROM_NAME="${APP_NAME}"

GOOGLE2FA_ENABLED=true

# PDF generation (spatie/laravel-pdf + Puppeteer/Browsershot)
LARAVEL_PDF_DRIVER=browsershot
LARAVEL_PDF_CHROME_PATH=
LARAVEL_PDF_NO_SANDBOX=true

# Cache store for print progress; must persist across requests.
# Use 'redis' if you run multiple app servers, otherwise 'file' is fine.
PRINT_PROGRESS_CACHE_STORE=file
```

## 4. Dependencies and build

```bash
composer install --no-dev --optimize-autoloader
npm install
npx puppeteer browsers install chrome
npm run build
```

## 5. Database

For SQLite:

```bash
touch database/database.sqlite
php artisan migrate --force
php artisan db:seed --force
```

For MySQL/PostgreSQL, create the database first, then run the migrate and seed commands.

Create the storage symlink:

```bash
php artisan storage:link
```

Create the Browsershot temp directory (required for Snap Chromium on Ubuntu 24.04):

```bash
mkdir -p storage/app/temp/browsershot
chgrp -R www-data storage/app/temp
chmod -R 775 storage/app/temp
```

## 6. Permissions

```bash
sudo chgrp -R www-data storage bootstrap/cache public/build
sudo chmod -R 775 storage bootstrap/cache

# Ensure Browsershot temp dir is writable
sudo chgrp -R www-data storage/app/temp
```

## 7. Queue worker (systemd)

The app dispatches jobs such as label printing via `PrintLabelsPCC`. A queue worker is required in production.

Create `/etc/systemd/system/pccs-queue.service`:

```ini
[Unit]
Description=PCCS Queue Worker
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/pccs/artisan queue:work --sleep=3 --tries=3 --max-time=3600
WorkingDirectory=/var/www/pccs

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable pccs-queue
sudo systemctl start pccs-queue
```

Verify that the worker user can access Node and Chromium:

```bash
sudo -u www-data node -v
sudo -u www-data npx puppeteer browsers find chrome
```

## 8. Scheduler

Add to the `www-data` crontab:

```bash
sudo crontab -u www-data -e
```

```
* * * * * cd /var/www/pccs && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

## 9. Apache2

Enable required modules:

```bash
sudo a2enmod rewrite proxy proxy_http ssl headers setenvif proxy_fcgi
sudo a2enconf php8.5-fpm
sudo systemctl restart apache2
```

Create `/etc/apache2/sites-available/pccs.conf`:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/pccs/public

    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}$1 [R=301,L]
</VirtualHost>

<VirtualHost *:443>
    ServerName your-domain.com
    DocumentRoot /var/www/pccs/public

    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/cloudflare-origin.pem
    SSLCertificateKeyFile /etc/ssl/private/cloudflare-origin.key

    <Directory /var/www/pccs/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ProxyPassMatch ^/(.*\.php)$ unix:/var/run/php/php8.5-fpm.sock|fcgi://127.0.0.1:9000/var/www/pccs/public/$1

    RequestHeader set X-Forwarded-Proto "https" env=HTTPS

    ErrorLog ${APACHE_LOG_DIR}/pccs-error.log
    CustomLog ${APACHE_LOG_DIR}/pccs-access.log combined
</VirtualHost>
```

Enable the site:

```bash
sudo a2ensite pccs
sudo a2dissite 000-default
sudo apache2ctl configtest
sudo systemctl reload apache2
```

## 10. SSL with Cloudflare origin certificate

1. In Cloudflare dashboard, go to **SSL/TLS > Origin Server** and create an origin certificate.
2. Save the certificate as `/etc/ssl/certs/cloudflare-origin.pem` and the private key as `/etc/ssl/private/cloudflare-origin.key`.

```bash
sudo nano /etc/ssl/certs/cloudflare-origin.pem
sudo nano /etc/ssl/private/cloudflare-origin.key
sudo chmod 600 /etc/ssl/private/cloudflare-origin.key
sudo chown root:root /etc/ssl/certs/cloudflare-origin.pem /etc/ssl/private/cloudflare-origin.key
```

3. Set Cloudflare SSL/TLS encryption mode to **Full (strict)**.

If you prefer to use Cloudflare's **Authenticated Origin Pulls**, upload their TLS client certificate and add this inside the `<VirtualHost *:443>` block:

```apache
SSLVerifyClient require
SSLCACertificateFile /etc/ssl/certs/cloudflare-origin-pull-ca.pem
```

## 11. Automated VPS setup

Run the setup script to install Chromium system libraries, configure Puppeteer, and verify the print pipeline:

```bash
sudo bash scripts/setup-vps.sh
```

The script handles:
- Installing npm dependencies (puppeteer)
- Verifying PHP dependencies (spatie/browsershot)
- Installing Chromium system libraries (libnspr4, libnss3, etc.)
- Downloading Puppeteer's Chrome browser
- Creating the Browsershot temp directory (avoids Snap Chromium sandbox issues)
- Detecting and persisting the Chromium binary path in `.env`

## 12. Updates

```bash
cd /var/www/pccs
php artisan down
git pull origin main
composer install --no-dev --optimize-autoloader
npm install
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
```

Then restart services:

```bash
sudo systemctl restart pccs-queue
sudo systemctl reload php8.5-fpm
sudo systemctl reload apache2
```

## Notes

- If you do not have Redis available, set `CACHE_STORE=database`, `SESSION_DRIVER=database`, and `QUEUE_CONNECTION=database`. Note that the app uses cache tags, so Redis is strongly recommended for production.
- **Node.js and Chromium are required for PDF/label printing.** The app uses Puppeteer (via `spatie/browsershot` + `spatie/laravel-pdf`) to generate label PDFs. Run `npx puppeteer browsers install chrome` after `npm install` to download a compatible Chromium.
- **Snap Chromium on Ubuntu 24.04**: If Chromium was installed via Snap (default on Ubuntu 24.04), it runs in a sandbox that blocks access to `/tmp/`. The app uses `storage/app/temp/browsershot` as the temp directory instead. Ensure that directory exists and is writable by the queue worker user. If you prefer not to use Snap Chromium, Puppeteer's own Chrome (installed via `npx puppeteer browsers install chrome`) avoids this issue entirely.
- If printing fails with `spatie/browsershot is missing`, run `composer install --no-dev --optimize-autoloader` — `spatie/browsershot` is a required dependency for PDF generation.
- If printing fails with `node: not found`, the queue worker or PHP-FPM process does not see Node in its `PATH`. Set `Environment=PATH=/usr/local/bin:/usr/bin:/bin` in your systemd service and verify with `sudo -u www-data node -v`.
- Always run `php artisan config:cache`, `route:cache`, and `view:cache` in production after deploying.
