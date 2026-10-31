#!/usr/bin/env bash
# Smoke test against a running server: pages, demo report, the PDF, and input validation.
set -euo pipefail
BASE="${BASE:-http://localhost:8080}"
check() { # name expected-status url
  local code
  code=$(curl -s -o /dev/null -w '%{http_code}' "${@:3}")
  [ "$code" = "$2" ] || { echo "FAIL $1: got $code, want $2"; exit 1; }
  echo "ok   $1"
}
curl -fsS "$BASE/health" | grep -q '"ok":true' && echo "ok   health"
check home 200 "$BASE/"
[ "${JUDGE_MODE:-0}" = "1" ] && check new-page-off 302 "$BASE/new" || check new-page 200 "$BASE/new"
SCAN=$(curl -fsS "$BASE/" | grep -oE '/scans/[0-9]+' | head -1 | cut -d/ -f3)
[ -n "$SCAN" ] || { echo "FAIL no demo scan: run php bin/seed_demo.php"; exit 1; }
check scan-page 200 "$BASE/scans/$SCAN"
check report 200 "$BASE/api/scans/$SCAN/report"
check work-orders 200 "$BASE/api/scans/$SCAN/work-orders"
check pack-pdf 200 "$BASE/api/scans/$SCAN/pack.pdf"
check overlay 200 "$BASE/media/$SCAN?f=det/0/overlay.jpg"
check lang-zh 200 "$BASE/?lang=zh"
check media-traversal 404 "$BASE/media/$SCAN?f=../../../.env"
check missing-scan 404 "$BASE/api/scans/999999"
# In judge mode these are refused before validation.
if [ "${JUDGE_MODE:-0}" = "1" ]; then
  check uploads-refused 403 -X POST "$BASE/api/households"
  check demo-read-only 403 -X PATCH -d '{"level":"green","reason":"x"}' "$BASE/api/hazards/1"
else
  check household-needs-name 422 -X POST "$BASE/api/households"
  check scan-needs-photos 422 -X POST "$BASE/api/households/1/scans"
  check voice-needs-audio 422 -X POST "$BASE/api/voice"
fi
curl -fsS "$BASE/api/scans/$SCAN/report" | grep -q '"hazards"' && echo "ok   report-has-hazards"
echo "all smoke checks passed"
