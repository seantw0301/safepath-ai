#!/usr/bin/env bash
# Render docs/diagrams/*.html to public/assets/*.png with headless Chrome (2x).
set -euo pipefail
cd "$(dirname "$0")/.."
CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
for spec in flow:1600,800 rules:1600,880 accuracy:1600,780; do
  name=${spec%%:*}; size=${spec#*:}
  "$CHROME" --headless=new --hide-scrollbars --force-device-scale-factor=2 --window-size="$size" \
    --screenshot="$PWD/public/assets/$name.png" "file://$PWD/docs/diagrams/$name.html" 2>/dev/null
  echo "ok   public/assets/$name.png"
done
# Shrink to 2400 px wide and palette-quantize so the page stays light.
python3 - <<'PY'
from PIL import Image
for n in ('flow', 'rules', 'accuracy'):
    p = f'public/assets/{n}.png'
    im = Image.open(p).convert('RGB')
    im = im.resize((2400, round(im.height * 2400 / im.width)), Image.LANCZOS)
    im.quantize(256, method=Image.Quantize.MEDIANCUT).save(p, optimize=True)
PY
