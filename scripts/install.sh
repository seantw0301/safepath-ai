#!/usr/bin/env bash
# One-shot install: PHP deps, samtool venv, .env, DB migration.
set -euo pipefail
cd "$(dirname "$0")/.."
composer install
[ -f .env ] || cp .env.example .env
python3.12 -m venv helpers/samtool/.venv
helpers/samtool/.venv/bin/pip install -r helpers/samtool/requirements.txt
php bin/migrate.php
