#!/usr/bin/env bash
# Local development install: PHP deps, samtool venv, .env, database.
set -euo pipefail
cd "$(dirname "$0")/.."
composer install
[ -f .env ] || { cp .env.example .env; chmod 600 .env; }
python3.12 -m venv helpers/samtool/.venv
helpers/samtool/.venv/bin/pip install -q -r helpers/samtool/requirements.txt
php bin/migrate.php
