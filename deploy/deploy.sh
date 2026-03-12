#!/bin/bash
#=============================================================================
# Script de despliegue - Automatización Gestión Garantisa
# Ubuntu 24.04 LTS + Nginx + PHP-FPM
#=============================================================================

set -e

# Colores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN} Despliegue Gestión Garantisa           ${NC}"
echo -e "${GREEN} Ubuntu 24.04 + Nginx + PHP 8.3         ${NC}"
echo -e "${GREEN}========================================${NC}"

# Variables - EDITAR SEGÚN TU SERVIDOR
APP_DIR="/var/www/comentarios"
APP_USER="www-data"
APP_GROUP="www-data"
DOMAIN="_"  # Cambiar por tu dominio o IP, "_" = cualquier host

# ============================================
# 1. Actualizar sistema e instalar paquetes
# ============================================
echo -e "\n${YELLOW}[1/7] Instalando paquetes del sistema...${NC}"
sudo apt update
sudo apt install -y \
    nginx \
    php8.3-fpm \
    php8.3-cli \
    php8.3-mbstring \
    php8.3-xml \
    php8.3-curl \
    php8.3-zip \
    php8.3-gd \
    php8.3-sqlite3 \
    php8.3-bcmath \
    php8.3-intl \
    php8.3-readline \
    unzip \
    curl \
    git \
    sqlite3

# Instalar Composer si no existe
if ! command -v composer &> /dev/null; then
    echo -e "${YELLOW}Instalando Composer...${NC}"
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
fi

# ============================================
# 2. Copiar proyecto
# ============================================
echo -e "\n${YELLOW}[2/7] Configurando directorio del proyecto...${NC}"
sudo mkdir -p "$APP_DIR"

# Si estás ejecutando desde el directorio del proyecto:
if [ -f "composer.json" ]; then
    echo "Copiando archivos del proyecto..."
    sudo rsync -av --exclude='vendor' --exclude='node_modules' --exclude='.env' \
        --exclude='storage/logs/*.log' --exclude='storage/logs/*.html' --exclude='storage/logs/*.txt' \
        --exclude='database/database.sqlite' \
        ./ "$APP_DIR/"
else
    echo -e "${RED}Ejecuta este script desde la raíz del proyecto Laravel${NC}"
    exit 1
fi

# ============================================
# 3. Instalar dependencias
# ============================================
echo -e "\n${YELLOW}[3/7] Instalando dependencias de Composer...${NC}"
cd "$APP_DIR"
sudo -u "$APP_USER" composer install --no-dev --optimize-autoloader 2>/dev/null || \
    composer install --no-dev --optimize-autoloader

# ============================================
# 4. Configurar .env
# ============================================
echo -e "\n${YELLOW}[4/7] Configurando .env...${NC}"
if [ ! -f "$APP_DIR/.env" ]; then
    sudo cp "$APP_DIR/.env.example" "$APP_DIR/.env"
    # Generar APP_KEY
    cd "$APP_DIR"
    php artisan key:generate
fi

# Ajustar valores de producción
sudo sed -i 's/APP_ENV=local/APP_ENV=production/' "$APP_DIR/.env"
sudo sed -i 's/APP_DEBUG=true/APP_DEBUG=false/' "$APP_DIR/.env"

echo -e "${YELLOW}IMPORTANTE: Edita $APP_DIR/.env y ajusta APP_URL con tu IP o dominio${NC}"

# ============================================
# 5. Permisos y base de datos
# ============================================
echo -e "\n${YELLOW}[5/7] Configurando permisos y base de datos...${NC}"

# Crear SQLite si no existe
sudo touch "$APP_DIR/database/database.sqlite"

# Permisos
sudo chown -R "$APP_USER":"$APP_GROUP" "$APP_DIR"
sudo chmod -R 755 "$APP_DIR"
sudo chmod -R 775 "$APP_DIR/storage"
sudo chmod -R 775 "$APP_DIR/bootstrap/cache"
sudo chmod 664 "$APP_DIR/database/database.sqlite"

# Migrar
cd "$APP_DIR"
sudo -u "$APP_USER" php artisan migrate --force

# Optimizar
sudo -u "$APP_USER" php artisan config:cache
sudo -u "$APP_USER" php artisan route:cache
sudo -u "$APP_USER" php artisan view:cache

# ============================================
# 6. Configurar Nginx
# ============================================
echo -e "\n${YELLOW}[6/7] Configurando Nginx...${NC}"

sudo tee /etc/nginx/sites-available/comentarios > /dev/null <<'NGINX'
server {
    listen 80;
    server_name _;
    root /var/www/comentarios/public;
    index index.php;

    charset utf-8;
    client_max_body_size 20M;

    # Logs
    access_log /var/log/nginx/comentarios_access.log;
    error_log  /var/log/nginx/comentarios_error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;

        # Timeouts largos para SSE (Server-Sent Events)
        fastcgi_read_timeout 600;
        fastcgi_send_timeout 600;
        fastcgi_buffering off;
        fastcgi_request_buffering off;
    }

    # SSE - desactivar buffering para streaming
    location = /process {
        try_files $uri /index.php?$query_string;
    }
    location ~ ^/process/ {
        try_files $uri /index.php?$query_string;
        proxy_buffering off;
        proxy_cache off;
    }

    # Bloquear archivos ocultos
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX

# Activar sitio y desactivar default
sudo ln -sf /etc/nginx/sites-available/comentarios /etc/nginx/sites-enabled/comentarios
sudo rm -f /etc/nginx/sites-enabled/default

# Validar config
sudo nginx -t

# ============================================
# 7. Configurar PHP-FPM para SSE
# ============================================
echo -e "\n${YELLOW}[7/7] Ajustando PHP-FPM para SSE...${NC}"

# Aumentar timeout y workers
PHP_FPM_POOL="/etc/php/8.3/fpm/pool.d/www.conf"
if [ -f "$PHP_FPM_POOL" ]; then
    # Aumentar max_execution_time para procesos largos
    sudo sed -i 's/;request_terminate_timeout = 0/request_terminate_timeout = 600/' "$PHP_FPM_POOL" 2>/dev/null || true
fi

# php.ini ajustes
PHP_INI="/etc/php/8.3/fpm/php.ini"
if [ -f "$PHP_INI" ]; then
    sudo sed -i 's/max_execution_time = 30/max_execution_time = 600/' "$PHP_INI"
    sudo sed -i 's/upload_max_filesize = 2M/upload_max_filesize = 20M/' "$PHP_INI"
    sudo sed -i 's/post_max_size = 8M/post_max_size = 25M/' "$PHP_INI"
    sudo sed -i 's/memory_limit = 128M/memory_limit = 256M/' "$PHP_INI"
    # Desactivar output_buffering para SSE
    sudo sed -i 's/output_buffering = 4096/output_buffering = Off/' "$PHP_INI"
fi

# ============================================
# Reiniciar servicios
# ============================================
echo -e "\n${YELLOW}Reiniciando servicios...${NC}"
sudo systemctl restart php8.3-fpm
sudo systemctl restart nginx
sudo systemctl enable php8.3-fpm
sudo systemctl enable nginx

# ============================================
# Resultado
# ============================================
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN} ¡Despliegue completado!                ${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "App:     http://$(hostname -I | awk '{print $1}')"
echo -e "Dir:     $APP_DIR"
echo -e "Logs:    $APP_DIR/storage/logs/"
echo -e ""
echo -e "${YELLOW}PASOS MANUALES:${NC}"
echo -e "1. Editar $APP_DIR/.env → ajustar APP_URL con tu IP/dominio"
echo -e "2. Si usas dominio, configurar DNS y opcionalmente SSL con certbot"
echo -e "3. Probar: curl http://localhost"
echo -e ""
echo -e "${YELLOW}COMANDOS ÚTILES:${NC}"
echo -e "  sudo tail -f /var/log/nginx/comentarios_error.log   # Errores Nginx"
echo -e "  sudo tail -f $APP_DIR/storage/logs/laravel.log      # Errores Laravel"
echo -e "  sudo systemctl status php8.3-fpm                    # Estado PHP-FPM"
echo -e "  sudo systemctl status nginx                         # Estado Nginx"
echo -e ""
