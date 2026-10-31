#!/usr/bin/env bash
# Install or update SafePath on a Linux host. Run from a checkout, as the user who owns it.
# It does not touch nginx/apache or systemd: see docs/deploy.md for those steps.
set -euo pipefail
cd "$(dirname "$0")/.."

for tool in php composer ffmpeg python3.12; do
  command -v "$tool" >/dev/null || { echo "missing: $tool (see docs/deploy.md)"; exit 1; }
done
php -r 'exit(version_compare(PHP_VERSION, "8.3", ">=") ? 0 : 1);' || { echo "PHP 8.3 or newer is required"; exit 1; }
[ -f .env ] || { cp .env.example .env; chmod 600 .env; echo "Created .env: fill in DB_* and META_API_KEY, set JUDGE_MODE=1, then run this again."; exit 1; }

composer install --no-dev --optimize-autoloader --no-interaction
[ -d helpers/samtool/.venv ] || python3.12 -m venv helpers/samtool/.venv
helpers/samtool/.venv/bin/pip install -q -r helpers/samtool/requirements.txt
mkdir -p storage
php bin/migrate.php
php bin/seed_demo.php
echo "Done. Restart the worker: sudo systemctl restart safepath-worker"
