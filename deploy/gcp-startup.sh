#!/bin/bash
# GCP VM first boot — Debian/Ubuntu + Apache + PHP 8.2 + MariaDB
# ใช้กับ Compute Engine startup script หรือรันครั้งเดียวหลัง SSH เข้า VM
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive

apt-get update
apt-get install -y apache2 mariadb-server \
  php php-mysqli php-curl php-mbstring php-xml php-zip php-gd php-cli \
  unzip git certbot python3-certbot-apache

# โฟลเดอร์แอป (ไม่มีช่องว่างใน path)
APP_DIR=/var/www/antigravity
mkdir -p "$APP_DIR"
chown -R www-data:www-data "$APP_DIR"

# Apache vhost
cat > /etc/apache2/sites-available/antigravity.conf <<'VHOST'
<VirtualHost *:80>
    ServerName antigravity.local
    DocumentRoot /var/www/antigravity
    <Directory /var/www/antigravity>
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog ${APACHE_LOG_DIR}/antigravity-error.log
    CustomLog ${APACHE_LOG_DIR}/antigravity-access.log combined
</VirtualHost>
VHOST

a2ensite antigravity.conf
a2dissite 000-default.conf 2>/dev/null || true
a2enmod rewrite headers ssl
systemctl reload apache2

# MariaDB — เปลี่ยนรหัสหลัง deploy
DB_PASS="${ANTIGRAVITY_DB_PASS:-ChangeMeAfterDeploy!}"
mysql -e "CREATE DATABASE IF NOT EXISTS antigravity_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS 'antigravity'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON antigravity_db.* TO 'antigravity'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

echo "GCP startup done. Upload app to ${APP_DIR} then import DB."
echo "DB user: antigravity  DB pass: ${DB_PASS}"
