diff --git a/ops/scripts/deploy.sh b/ops/scripts/deploy.sh
old mode 100644
new mode 100755
index ca5dc0d49b9e699bcaed4d9de362bf09959d8ab2..7da82c6d25e5adf6d27d3b22cb0a4931d7e9634b
--- a/ops/scripts/deploy.sh
+++ b/ops/scripts/deploy.sh
@@ -1,35 +1,78 @@
 #!/usr/bin/env bash
 set -euo pipefail
 
-PROJECT_DIR=/var/www/zk-g4s-fel-php
-sudo mkdir -p "$PROJECT_DIR"
-sudo rsync -a --delete ./ "$PROJECT_DIR"/
+if [[ ! -d backend || ! -d ops ]]; then
+  echo "Ejecuta este script desde la raíz del repositorio (donde están backend/ y ops/)." >&2
+  exit 1
+fi
+
+TARGET_DIR=${TARGET_DIR:-/var/www/zk-g4s-fel-php}
+
+if command -v sudo >/dev/null 2>&1; then
+  SUDO=(sudo)
+  SUDO_WWW=(sudo -u www-data)
+else
+  if [[ ${EUID:-0} -ne 0 ]]; then
+    echo "Este script necesita privilegios de root o el comando sudo." >&2
+    exit 1
+  fi
+  SUDO=()
+  if command -v runuser >/dev/null 2>&1 && id -u www-data >/dev/null 2>&1; then
+    SUDO_WWW=(runuser -u www-data --)
+  else
+    SUDO_WWW=()
+  fi
+fi
 
-# Set permissions
-sudo chown -R www-data:www-data "$PROJECT_DIR"/backend/storage
-sudo chmod -R 775 "$PROJECT_DIR"/backend/storage
+cleanup() {
+  if [[ -n ${TMP_CONF:-} && -f $TMP_CONF ]]; then
+    rm -f "$TMP_CONF"
+  fi
+}
+trap cleanup EXIT
+
+echo "Sincronizando archivos con $TARGET_DIR ..."
+"${SUDO[@]}" mkdir -p "$TARGET_DIR"
+"${SUDO[@]}" rsync -a --delete --exclude ".git" ./ "$TARGET_DIR"/
+
+echo "Asegurando archivo .env ..."
+if ! "${SUDO[@]}" test -f "$TARGET_DIR/backend/.env"; then
+  "${SUDO[@]}" cp "$TARGET_DIR/backend/.env.sample" "$TARGET_DIR/backend/.env"
+fi
 
-# Copy example env if missing
-if [ ! -f "$PROJECT_DIR/backend/.env" ]; then
-  cp "$PROJECT_DIR/backend/.env.sample" "$PROJECT_DIR/backend/.env"
+echo "Inicializando base de datos SQLite ..."
+if [[ ${#SUDO_WWW[@]} -gt 0 ]]; then
+  "${SUDO_WWW[@]}" php "$TARGET_DIR/backend/ops_init_db.php"
+else
+  php "$TARGET_DIR/backend/ops_init_db.php"
 fi
 
-# Initialize SQLite DB
-php "$PROJECT_DIR/backend/ops_init_db.php"
+echo "Ajustando permisos de storage ..."
+"${SUDO[@]}" chown -R www-data:www-data "$TARGET_DIR/backend/storage"
+"${SUDO[@]}" chmod -R 775 "$TARGET_DIR/backend/storage"
 
-# NGINX (optional)
+echo "Configurando Nginx (si está instalado) ..."
 if command -v nginx >/dev/null 2>&1; then
-  sudo cp "$PROJECT_DIR/ops/nginx/site.conf" /etc/nginx/sites-available/zk-g4s-fel.conf
-  sudo ln -sf /etc/nginx/sites-available/zk-g4s-fel.conf /etc/nginx/sites-enabled/zk-g4s-fel.conf
-  sudo nginx -t && sudo systemctl reload nginx
+  PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
+  PHP_FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"
+  if [ ! -S "$PHP_FPM_SOCKET" ]; then
+    PHP_FPM_SOCKET="/run/php/php-fpm.sock"
+  fi
+  TMP_CONF=$(mktemp)
+  sed "s|__PHP_FPM_SOCKET__|$PHP_FPM_SOCKET|" \
+    "$TARGET_DIR/ops/nginx/site.conf.template" > "$TMP_CONF"
+  "${SUDO[@]}" cp "$TMP_CONF" /etc/nginx/sites-available/zk-g4s-fel.conf
+  "${SUDO[@]}" ln -sf /etc/nginx/sites-available/zk-g4s-fel.conf /etc/nginx/sites-enabled/zk-g4s-fel.conf
+  "${SUDO[@]}" nginx -t
+  "${SUDO[@]}" systemctl reload nginx
 fi
 
-# Apache (optional)
+echo "Configurando Apache (si está instalado) ..."
 if command -v apache2 >/dev/null 2>&1; then
-  sudo cp "$PROJECT_DIR/ops/apache/vhost.conf" /etc/apache2/sites-available/zk-g4s-fel.conf
-  sudo a2ensite zk-g4s-fel.conf
-  sudo a2enmod rewrite
-  sudo systemctl reload apache2
+  "${SUDO[@]}" cp "$TARGET_DIR/ops/apache/vhost.conf" /etc/apache2/sites-available/zk-g4s-fel.conf
+  "${SUDO[@]}" a2ensite zk-g4s-fel.conf
+  "${SUDO[@]}" a2enmod rewrite
+  "${SUDO[@]}" systemctl reload apache2
 fi
 
-echo "Deployment finished. Visit http://<your-server-ip>/"
+echo "Deployment finished. Visita http://<tu-ip-del-servidor>/"
