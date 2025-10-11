#!/usr/bin/env bash
set -euo pipefail

php ops_init_db.php

exec heroku-php-apache2 public/
