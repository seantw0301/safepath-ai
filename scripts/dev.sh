#!/usr/bin/env bash
# Start the web server (8080) and the job worker for local development.
set -euo pipefail
cd "$(dirname "$0")/.."
php bin/worker.php &
WORKER=$!
trap 'kill $WORKER 2>/dev/null' EXIT
php -d upload_max_filesize=64M -d post_max_size=512M -d max_file_uploads=20 -S localhost:"${PORT:-8080}" -t public public/index.php
