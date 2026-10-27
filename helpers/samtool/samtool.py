"""samtool: parse SAM 3.1 output, decode masks, draw overlays, measure with an A4 reference.

Called by the PHP worker; every command prints JSON to stdout.

  samtool.py parse   --media image|video --label L --out-dir D  < sam_output.txt
  samtool.py overlay --image IMG --objects a.json [b.json ...] --out OUT.png
  samtool.py measure --reference a4.json --objects x.json [...]
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw
from meta_sam_parser import (
    SegmentationBoxRecord,
    SegmentationMaskRecord,
    decode_mask_to_raster,
    image_segmentation_format,
    video_segmentation_format,
)

A4_CM = (21.0, 29.7)
COLORS = [(230, 57, 70), (255, 183, 3), (42, 157, 143), (69, 123, 157), (155, 93, 229), (255, 255, 255)]


def parse(args: argparse.Namespace) -> dict:
    fmt = image_segmentation_format() if args.media == "image" else video_segmentation_format()
    parser = fmt.create_parser()
    snapshots = parser.push(sys.stdin.read() + "\n")
    records = snapshots[-1].records if snapshots else ()
    diagnostics = [d.code for d in snapshots[-1].diagnostics] if snapshots else []

    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    confidence: dict[tuple, float | None] = {}
    for r in records:
        if isinstance(r, SegmentationBoxRecord):
            confidence[(r.object_id, r.frame.frame_index if r.frame else 0)] = r.confidence

    objects = []
    for r in records:
        if not isinstance(r, SegmentationMaskRecord):
            continue
        frame = r.frame.frame_index if r.frame else 0
        raster = np.frombuffer(decode_mask_to_raster(r.mask), dtype=np.uint8).reshape(r.mask.height, r.mask.width)
        mask_path = out_dir / f"{args.label}_o{r.object_id}_f{frame}.png"
        Image.fromarray(raster * 255).save(mask_path)
        b = r.bounds
        objects.append({
            "label": args.label,
            "object_id": r.object_id,
            "frame": frame,
            "bounds": [b.left, b.top, b.right, b.bottom],
            "confidence": r.confidence if r.confidence is not None else confidence.get((r.object_id, frame)),
            "mask": str(mask_path),
            "area_px": int(raster.sum() * ((b.right - b.left) * (b.bottom - b.top)) / max(1, raster.size)),
        })
    return {"objects": objects, "diagnostics": diagnostics}


def _full_mask(obj: dict, size: tuple[int, int]) -> np.ndarray:
    """Place a box-local mask raster into a full-frame boolean array."""
    w, h = size
    left, top, right, bottom = (int(round(v)) for v in obj["bounds"])
    local = Image.open(obj["mask"]).resize((max(1, right - left), max(1, bottom - top)), Image.NEAREST)
    full = np.zeros((h, w), dtype=bool)
    arr = np.asarray(local) > 127
    full[top:top + arr.shape[0], left:left + arr.shape[1]] = arr[: h - top, : w - left]
    return full


def overlay(args: argparse.Namespace) -> dict:
    img = Image.open(args.image).convert("RGB")
    canvas = np.asarray(img).astype(np.float32)
    draw_boxes = []
    for i, path in enumerate(args.objects):
        color = np.array(COLORS[i % len(COLORS)], dtype=np.float32)
        for obj in json.loads(Path(path).read_text())["objects"]:
            if obj["frame"] != args.frame:
                continue
            m = _full_mask(obj, img.size)
            canvas[m] = canvas[m] * 0.45 + color * 0.55
            draw_boxes.append((obj, tuple(int(c) for c in color)))
    out = Image.fromarray(canvas.astype(np.uint8))
    draw = ImageDraw.Draw(out)
    for obj, color in draw_boxes:
        draw.rectangle(obj["bounds"], outline=color, width=3)
        draw.text((obj["bounds"][0] + 4, obj["bounds"][1] + 4), f'{obj["label"]} #{obj["object_id"]}', fill=color)
    out.save(args.out)
    return {"overlay": args.out, "objects": len(draw_boxes)}


def _principal_extent(mask: np.ndarray) -> tuple[float, float]:
    """Long and short side (px) of a mask via PCA of its pixels."""
    ys, xs = np.nonzero(mask)
    pts = np.stack([xs, ys], axis=1).astype(np.float64)
    pts -= pts.mean(axis=0)
    _, vecs = np.linalg.eigh(np.cov(pts.T))
    proj = pts @ vecs
    spans = proj.max(axis=0) - proj.min(axis=0)
    return float(spans.max()), float(spans.min())


def measure(args: argparse.Namespace) -> dict:
    ref = json.loads(Path(args.reference).read_text())["objects"]
    if not ref:
        return {"status": "no_reference"}
    best = max(ref, key=lambda o: o["confidence"] or 0)
    size = Image.open(args.image).size
    long_px, short_px = _principal_extent(_full_mask(best, size))
    px_per_cm = (long_px / A4_CM[1] + short_px / A4_CM[0]) / 2
    aspect_error = abs((long_px / short_px) - (A4_CM[1] / A4_CM[0])) / (A4_CM[1] / A4_CM[0])

    results = []
    for path in args.objects:
        for obj in json.loads(Path(path).read_text())["objects"]:
            m = _full_mask(obj, size)
            ys, xs = np.nonzero(m)
            if xs.size == 0:
                continue
            long_obj, short_obj = _principal_extent(m)
            results.append({
                "label": obj["label"],
                "object_id": obj["object_id"],
                "frame": obj["frame"],
                "width_cm": round((xs.max() - xs.min()) / px_per_cm, 1),
                "height_cm": round((ys.max() - ys.min()) / px_per_cm, 1),
                "long_cm": round(long_obj / px_per_cm, 1),
                "short_cm": round(short_obj / px_per_cm, 1),
            })
    return {
        "px_per_cm": round(px_per_cm, 3),
        "reference_aspect_error": round(aspect_error, 3),
        "note": "single-plane estimate: valid only for objects near the reference's depth",
        "measurements": results,
    }


def main() -> int:
    ap = argparse.ArgumentParser(prog="samtool")
    sub = ap.add_subparsers(dest="cmd", required=True)
    p = sub.add_parser("parse")
    p.add_argument("--media", choices=["image", "video"], default="image")
    p.add_argument("--label", required=True)
    p.add_argument("--out-dir", required=True)
    o = sub.add_parser("overlay")
    o.add_argument("--image", required=True)
    o.add_argument("--objects", nargs="+", required=True)
    o.add_argument("--frame", type=int, default=0)
    o.add_argument("--out", required=True)
    m = sub.add_parser("measure")
    m.add_argument("--image", required=True)
    m.add_argument("--reference", required=True)
    m.add_argument("--objects", nargs="+", default=[])
    args = ap.parse_args()
    result = {"parse": parse, "overlay": overlay, "measure": measure}[args.cmd](args)
    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
