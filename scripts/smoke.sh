#!/usr/bin/env bash
# Smoke test: health check (scan upload check added in M1).
set -euo pipefail
BASE="${BASE:-http://localhost:8080}"
curl -fsS "$BASE/health" | grep -q '"ok":true' && echo "health ok"
