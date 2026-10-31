#!/usr/bin/env bash
# Builds a SIMULATED walkthrough clip (slow zoom over the generated scenes). Not real camera motion.
# Usage: scripts/gen_walk_video.sh before|after OUT.mp4
set -euo pipefail
cd "$(dirname "$0")/.."
PHASE="$1"; OUT="$2"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
for s in door hall bath; do
  ffmpeg -y -loglevel error -loop 1 -t 6 -i "samples/scenes/${s}_${PHASE}.jpg" \
    -vf "scale=1920:1280,zoompan=z='1.0+0.004*on':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=36:s=1280x854:fps=6" \
    -frames:v 36 -c:v libx264 -pix_fmt yuv420p "$TMP/$s.mp4"
  echo "file '$TMP/$s.mp4'" >> "$TMP/list.txt"
done
ffmpeg -y -loglevel error -f concat -safe 0 -i "$TMP/list.txt" -c copy "$OUT"
