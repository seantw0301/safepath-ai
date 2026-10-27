#!/usr/bin/env bash
# Start web server (8080) and worker for local development.
set -euo pipefail
cd "$(dirname "$0")/.."
php bin/worker.php &
WORKER=$!
trap 'kill $WORKER 2>/dev/null' EXIT
php -S localhost:8080 -t public
