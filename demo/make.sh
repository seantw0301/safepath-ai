#!/usr/bin/env bash
# Rebuilds docs/demo-final.mp4: screen recording of the app (real home + labelled simulated scenes),
# Kokoro narration at -16 LUFS, subtitles burned in by the recorder.
# Needs: APP_DIR (checkout with .env, vendor/, storage/ and the scans already run; default: this repo),
# PLAYWRIGHT_DIR (node_modules/playwright), KOKORO_ENV (venv with kokoro_onnx, model/ inside).
# Scans: REAL_DOORS (IMG_9796-9800 photos), REAL_HALL (IMG_9805 video, re-shot with IMG_9806), SIM (demo home).
set -euo pipefail
cd "$(dirname "$0")/.."
APP=${APP_DIR:-$PWD}
REAL_DOORS=${REAL_DOORS:-10} REAL_HALL=${REAL_HALL:-7} SIM=${SIM:-1}
OUT=$APP/storage/video/final
MEDIA=$OUT/media
WAV=$OUT/wav
S=$APP/storage
rm -rf "$OUT"; mkdir -p "$MEDIA"

# Media. The hallway video starts at 2 s (a person's body is in the first second) and the re-shoot at 0.5 s.
ffmpeg -y -loglevel error -i "$S/real/bathroom-door/IMG_9802.jpg" -vf "scale=-2:1040" -q:v 3 "$MEDIA/tape.jpg"
ffmpeg -y -loglevel error -i "$S/scans/$REAL_DOORS/det/2/overlay.jpg" -vf "crop=930:ih:150:0,scale=-2:800" -q:v 3 "$MEDIA/door_front.jpg" # shelf with papers cropped out
ffmpeg -y -loglevel error -i "$S/scans/$REAL_DOORS/det/4/overlay.jpg" -vf "scale=-2:800" -q:v 3 "$MEDIA/door_bath.jpg"
ffmpeg -y -loglevel error -i "$S/scans/$REAL_HALL/det/2/overlay.jpg" -vf "scale=-2:940" -q:v 3 "$MEDIA/hall_red.jpg"
ffmpeg -y -loglevel error -ss 2 -i "$S/real/hallway/IMG_9805.mp4" -an -vf "scale=-2:940" -c:v libvpx-vp9 -b:v 2M "$MEDIA/hall_before.webm"
ffmpeg -y -loglevel error -ss 0.5 -i "$S/real/hallway/IMG_9806.mp4" -an -vf "scale=-2:940" -c:v libvpx-vp9 -b:v 2M "$MEDIA/hall_after.webm"
cp samples/scenes/false_positive.jpg "$MEDIA/"
sips -s format png -Z 1800 "$S/scans/$SIM/pack.pdf" --out "$MEDIA/pack.png" >/dev/null
"$APP/helpers/samtool/.venv/bin/python" -I -c "import sys;from PIL import Image;i=Image.open(sys.argv[1]);w,h=i.size;i.crop((0,0,w,int(h*.47))).save(sys.argv[1])" "$MEDIA/pack.png"

# Narration first: the recorder times the subtitles from the speech lengths.
KOKORO_MODEL=$KOKORO_ENV/model/kokoro-v1.0.onnx KOKORO_VOICES=$KOKORO_ENV/model/voices-v1.0.bin \
  "$KOKORO_ENV/bin/python" demo/voice.py tts demo/cues.json "$WAV"

(cd "$APP" && PORT=8093 exec scripts/dev.sh >"$OUT/dev.log" 2>&1) &
SERVER=$!
trap 'kill $SERVER 2>/dev/null; pkill -f "localhost:8093" || true' EXIT
sleep 2
BASE=http://localhost:8093 MEDIA=$MEDIA DURATIONS=$WAV/durations.json REAL_DOORS=$REAL_DOORS REAL_HALL=$REAL_HALL SIM=$SIM \
  node demo/record.mjs "$OUT"
FILE=$(python3 -c "import json;print(json.load(open('$OUT/meta.json'))['file'])")
SKIP=$(python3 -c "import json;print(json.load(open('$OUT/meta.json'))['skip'])")
LONG=$(python3 -c "import json;print(json.load(open('$OUT/meta.json'))['seconds'])")
ffmpeg -y -loglevel error -ss "$SKIP" -t "$LONG" -i "$FILE" -c:v libx264 -crf 20 -pix_fmt yuv420p -r 30 "$OUT/silent.mp4"
"$KOKORO_ENV/bin/python" demo/voice.py mix demo/cues.json "$WAV" "$OUT/silent.mp4" docs/demo-final.mp4
"$KOKORO_ENV/bin/python" demo/voice.py srt demo/cues.json "$WAV" docs/demo-final.srt
ffmpeg -y -loglevel error -i docs/demo-final.mp4 -vf "fps=12/$LONG,scale=640:-2,tile=4x3" -frames:v 1 docs/demo-final-contact.jpg
