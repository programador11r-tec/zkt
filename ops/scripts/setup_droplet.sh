
#!/usr/bin/env bash
set -euo pipefail

if ! command -v apt-get >/dev/null 2>&1; then
  echo "Este script está pensado para distribuciones basadas en Debian/Ubuntu." >&2
  exit 1
fi

if [[ $EUID -ne 0 ]]; then
  SUDO="sudo"
else
  SUDO=""
fi

$SUDO apt-get update
$SUDO apt-get install -y nginx php php-fpm php-cli php-sqlite3 sqlite3 unzip rsync

# Ensure services are enabled
if command -v systemctl >/dev/null 2>&1; then
  $SUDO systemctl enable --now nginx
  PHP_FPM_SERVICE=$(systemctl list-units --type=service | awk '/php[0-9]\.[0-9]-fpm\.service/ {print $1; exit}')
  if [[ -n ${PHP_FPM_SERVICE:-} ]]; then
    $SUDO systemctl enable --now "$PHP_FPM_SERVICE"
  fi
fi

echo "Base packages installed. Continue with ops/scripts/deploy.sh"