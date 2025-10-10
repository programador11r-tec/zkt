#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR=/var/www/zk-g4s-fel-php
sudo mkdir -p "$PROJECT_DIR"
sudo rsync -a --delete ./ "$PROJECT_DIR"/

# Set permissions
sudo chown -R www-data:www-data "$PROJECT_DIR"/backend/storage
sudo chmod -R 775 "$PROJECT_DIR"/backend/storage

# Copy example env if missing
if [ ! -f "$PROJECT_DIR/backend/.env" ]; then
  cp "$PROJECT_DIR/backend/.env.sample" "$PROJECT_DIR/backend/.env"
fi

# Initialize SQLite DB
php "$PROJECT_DIR/backend/ops_init_db.php"

# NGINX (optional)
if command -v nginx >/dev/null 2>&1; then
  sudo cp "$PROJECT_DIR/ops/nginx/site.conf" /etc/nginx/sites-available/zk-g4s-fel.conf
  sudo ln -sf /etc/nginx/sites-available/zk-g4s-fel.conf /etc/nginx/sites-enabled/zk-g4s-fel.conf
  sudo nginx -t && sudo systemctl reload nginx
fi

# Apache (optional)
if command -v apache2 >/dev/null 2>&1; then
  sudo cp "$PROJECT_DIR/ops/apache/vhost.conf" /etc/apache2/sites-available/zk-g4s-fel.conf
  sudo a2ensite zk-g4s-fel.conf
  sudo a2enmod rewrite
  sudo systemctl reload apache2
fi

echo "Deployment finished. Visit http://<your-server-ip>/"
