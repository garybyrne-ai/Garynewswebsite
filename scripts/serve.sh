#!/usr/bin/env bash
# Local development server for ME News Ireland.
set -euo pipefail
cd "$(dirname "$0")/.."
if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example — edit ADMIN_EMAIL / ADMIN_PASSWORD if you wish."
fi
if [ ! -f storage/data/menews.sqlite ]; then
  php scripts/setup.php --seed
fi
PORT="${1:-8000}"
echo "ME News Ireland → http://127.0.0.1:${PORT}"
exec php -S "127.0.0.1:${PORT}" -t public public/router.php
