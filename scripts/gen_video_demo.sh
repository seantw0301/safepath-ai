#!/usr/bin/env bash
# Builds samples/demo-video from the simulated scenes: the same normalising the app does for an
# upload, kept as files so the demo replays from the model cache on any host.
set -euo pipefail
cd "$(dirname "$0")/.."
for phase in before after; do
  dir="samples/demo-video/$phase"
  mkdir -p "$dir"
  tmp=$(mktemp -d)
  scripts/gen_walk_video.sh "$phase" "$tmp/raw.mp4"
  ffmpeg -y -loglevel error -i "$tmp/raw.mp4" -t 60 -vf "fps=3,scale='min(1280,iw)':-2" -an -c:v libx264 -pix_fmt yuv420p "$dir/walk.mp4"
  ffmpeg -y -loglevel error -i "$dir/walk.mp4" -fps_mode passthrough "$tmp/%d.jpg"
  count=$(ls "$tmp"/*.jpg | wc -l | tr -d ' ')
  step=$(( (count + 5) / 6 ))
  rm -f "$dir"/*.jpg
  keys=()
  for ((n = 0; n < count; n += step)); do cp "$tmp/$((n + 1)).jpg" "$dir/$n.jpg"; keys+=("$n"); done
  printf '{"frames": %d, "keyframes": [%s]}\n' "$count" "$(IFS=,; echo "${keys[*]}")" > "$dir/manifest.json"
  rm -rf "$tmp"
done
