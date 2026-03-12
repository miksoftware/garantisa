#!/bin/bash
# Script para actualizar el código en el servidor
# Uso: Copia los archivos nuevos y ejecuta este script
APP_DIR="/var/www/comentarios"

set -e

cd "$APP_DIR"

echo "Instalando dependencias..."
sudo -u www-data composer install --no-dev --optimize-autoloader

echo "Migrando base de datos..."
sudo -u www-data php artisan migrate --force

echo "Limpiando caché..."
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

echo "Corrigiendo permisos..."
sudo chown -R www-data:www-data "$APP_DIR"
sudo chmod -R 775 "$APP_DIR/storage"
sudo chmod -R 775 "$APP_DIR/bootstrap/cache"

echo "Reiniciando PHP-FPM..."
sudo systemctl restart php8.3-fpm

echo "¡Actualización completada!"
