#!/bin/bash
# Fix rápido de permisos después de actualizar código
APP_DIR="/var/www/comentarios"

sudo chown -R www-data:www-data "$APP_DIR"
sudo chmod -R 755 "$APP_DIR"
sudo chmod -R 775 "$APP_DIR/storage"
sudo chmod -R 775 "$APP_DIR/bootstrap/cache"
sudo chmod 664 "$APP_DIR/database/database.sqlite"

cd "$APP_DIR"
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

echo "Permisos corregidos y caché regenerada."
