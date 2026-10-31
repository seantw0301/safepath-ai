#!/usr/bin/env bash
# Rebuilds docs/demo-provisional.mp4: screen recording of the simulated demo + Kokoro narration.
# Needs: PLAYWRIGHT_DIR (node_modules/playwright), KOKORO_ENV and VOICE_PY (mixes demo/cues.json onto a video).
set -euo pipefail
cd "$(dirname "$0")/.."
OUT=storage/video/rec
rm -rf "$OUT"; mkdir -p "$OUT"
sips -s format png -Z 1800 storage/scans/1/pack.pdf --out "$OUT/pack.png" >/dev/null
helpers/samtool/.venv/bin/python -I -c "import sys;from PIL import Image;i=Image.open(sys.argv[1]);w,h=i.size;i.crop((0,0,w,int(h*.47))).save(sys.argv[1])" "$OUT/pack.png"
PORT=8093 scripts/dev.sh >storage/dev.log 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null; pkill -f "localhost:8093" || true; pkill -f bin/worker.php || true' EXIT
sleep 2
PACK_IMAGE="$PWD/$OUT/pack.png" BASE=http://localhost:8093 node demo/record.mjs "$OUT"
FILE=$(python3 -c "import json;print(json.load(open('$OUT/meta.json'))['file'])")
SKIP=$(python3 -c "import json;print(json.load(open('$OUT/meta.json'))['skip'])")
SECONDS_LONG=$(python3 -c "import json;print(json.load(open('$OUT/meta.json'))['seconds'])")
ffmpeg -y -loglevel error -ss "$SKIP" -t "$SECONDS_LONG" -i "$FILE" -c:v libx264 -crf 22 -pix_fmt yuv420p -r 30 "$OUT/silent.mp4"
"$KOKORO_ENV/bin/python" "$VOICE_PY" "$OUT/silent.mp4" docs/demo-provisional.mp4 demo/cues.json
