"""samtool: parse SAM 3.1 output, decode masks, draw overlays, measure with an A4 reference.

Called by the PHP worker; every command prints JSON to stdout.

  samtool.py parse   --media image|video --label L --out-dir D  < sam_output.txt
  samtool.py overlay --image IMG --objects a.json [b.json ...] --out OUT.png
  samtool.py measure --reference a4.json --objects x.json [...]
  samtool.py path    --image IMG --floor floor.json [--doorway door.json] [--line x1,y1,x2,y2...] --objects x.json [...]
  samtool.py blur    --image IMG --objects person.json --out OUT.jpg
  samtool.py annotate --image IMG --spec spec.json --out OUT.jpg
  samtool.py pdf     --spec pack.json --out OUT.pdf
"""
from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont
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
    arr = arr[: h - top, : w - left]
    full[top:top + arr.shape[0], left:left + arr.shape[1]] = arr
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


def _corridor(size: tuple[int, int], floor: np.ndarray, door: dict | None, line: list[float] | None) -> np.ndarray:
    """Walking corridor polygon: a user-drawn polyline, else bottom-centre of frame to the doorway's foot."""
    w, h = size
    img = Image.new("L", size, 0)
    draw = ImageDraw.Draw(img)
    if line:
        pts = list(zip(line[0::2], line[1::2]))
        draw.line(pts, fill=255, width=int(w * 0.18), joint="curve")
    else:
        ys, xs = np.nonzero(floor)
        if door is not None:
            l, _, r, b = door["bounds"]
            top_l, top_r, top_y = l, r, b
        else:  # no doorway: aim at the far end of the floor
            top_y = int(ys.min())
            row = xs[ys == top_y]
            top_l, top_r = float(row.min()), float(row.max())
        half = w * 0.2
        cx = w / 2
        draw.polygon([(cx - half, h), (cx + half, h), (top_r, top_y), (top_l, top_y)], fill=255)
    # Objects occlude the floor mask, so fill each row between the floor's outer edges.
    filled = np.zeros_like(floor)
    for y in np.unique(np.nonzero(floor)[0]):
        row = np.nonzero(floor[y])[0]
        filled[y, row.min():row.max() + 1] = True
    return (np.asarray(img) > 0) & filled


def path(args: argparse.Namespace) -> dict:
    img = Image.open(args.image)
    size = img.size
    floor_objs = json.loads(Path(args.floor).read_text())["objects"]
    floor = np.zeros((size[1], size[0]), dtype=bool)
    for o in floor_objs:
        floor |= _full_mask(o, size)
    door = None
    if args.doorway:
        doors = json.loads(Path(args.doorway).read_text())["objects"]
        door = max(doors, key=lambda o: o["confidence"] or 0) if doors else None
    line = [float(v) for v in args.line.split(",")] if args.line else None
    corridor = _corridor(size, floor, door, line)

    results = []
    masks = []
    for p in args.objects:
        for obj in json.loads(Path(p).read_text())["objects"]:
            m = _full_mask(obj, size)
            masks.append(m)
            area = int(m.sum())
            if area == 0:
                continue
            overlap = int((m & corridor).sum()) / area
            results.append({
                "label": obj["label"],
                "object_id": obj["object_id"],
                "confidence": obj["confidence"],
                "on_path_ratio": round(overlap, 3),
                "verdict": "hazard" if overlap >= args.min_overlap else "noted",
                "action": "清除或移位" if overlap >= args.min_overlap else "不在動線上，僅記錄",
            })
    if args.out:
        canvas = np.asarray(img.convert("RGB")).astype(np.float32)
        canvas[corridor] = canvas[corridor] * 0.6 + np.array([42, 157, 143]) * 0.4
        for m, r in zip(masks, results):
            color = np.array([230, 57, 70] if r["verdict"] == "hazard" else [160, 160, 160], dtype=np.float32)
            canvas[m] = canvas[m] * 0.35 + color * 0.65
        out = Image.fromarray(canvas.astype(np.uint8))
        draw = ImageDraw.Draw(out)
        for m, r in zip(masks, results):
            ys, xs = np.nonzero(m)
            tag = f'{r["label"]}: {"CLEAR" if r["verdict"] == "hazard" else "ok"}'
            draw.text((int(xs.min()), max(0, int(ys.min()) - 14)), tag, fill=(255, 255, 255))
        out.save(args.out)
    if args.corridor_out:
        Image.fromarray((corridor * 255).astype(np.uint8)).save(args.corridor_out)
    return {"corridor_px": int(corridor.sum()), "objects": results, "overlay": args.out}


def blur(args: argparse.Namespace) -> dict:
    """Blur every person so evidence frames never show a face."""
    img = Image.open(args.image).convert("RGB")
    objects = []
    for p in args.objects:
        objects += json.loads(Path(p).read_text())["objects"]
    if objects:
        mask = np.zeros((img.size[1], img.size[0]), dtype=bool)
        for obj in objects:
            mask |= _full_mask(obj, img.size)
        blurred = img.filter(ImageFilter.GaussianBlur(radius=max(img.size) // 40))
        img = Image.composite(blurred, img, Image.fromarray((mask * 255).astype(np.uint8)))
    img.save(args.out, quality=90)
    return {"out": args.out, "people": len(objects)}


LEVEL_COLORS = {"red": (230, 57, 70), "yellow": (255, 183, 3), "green": (42, 157, 143), "unknown": (160, 160, 160)}


def annotate(args: argparse.Namespace) -> dict:
    """Draw the walking corridor, hazard masks and short tags on an evidence frame."""
    img = Image.open(args.image).convert("RGB")
    spec = json.loads(Path(args.spec).read_text())
    canvas = np.asarray(img).astype(np.float32)
    if spec.get("corridor"):
        m = np.asarray(Image.open(spec["corridor"])) > 127
        canvas[m] = canvas[m] * 0.65 + np.array([69, 123, 157]) * 0.35
    for item in spec["items"]:
        if item.get("mask"):
            m = _full_mask(item, img.size)
            canvas[m] = canvas[m] * 0.4 + np.array(LEVEL_COLORS[item["level"]], dtype=np.float32) * 0.6
    out = Image.fromarray(canvas.astype(np.uint8))
    draw = ImageDraw.Draw(out)
    font = ImageFont.load_default(size=max(18, img.size[0] // 60))
    placed: list[tuple[float, float, float, float]] = []
    for item in spec["items"]:
        if not item.get("bounds"):
            continue
        left, top, right, bottom = item["bounds"]
        color = LEVEL_COLORS[item["level"]]
        draw.rectangle([left, top, right, bottom], outline=color, width=3)
        box = draw.textbbox((left, top), item["tag"], font=font)
        tag_w, tag_h = box[2] - box[0] + 12, box[3] - box[1] + 10
        x = min(left, img.size[0] - tag_w)
        y = max(0, top - tag_h)
        # Stack tags downward so neighbouring hazards stay readable.
        while any(x < r and x + tag_w > l and y < b and y + tag_h > t for l, t, r, b in placed):
            y += tag_h + 2
        placed.append((x, y, x + tag_w, y + tag_h))
        draw.rectangle([x, y, x + tag_w, y + tag_h], fill=color)
        draw.text((x + 6, y + 2), item["tag"], fill=(255, 255, 255), font=font)
    out.save(args.out, quality=88)
    return {"out": args.out}


def pdf(args: argparse.Namespace) -> dict:
    """Render the subsidy draft with an embedded CJK font (so any viewer shows it), else the MSung CID font."""
    from reportlab.lib.pagesizes import A4
    from reportlab.lib.styles import ParagraphStyle
    from reportlab.lib.units import cm
    from reportlab.lib import colors
    from reportlab.pdfbase import pdfmetrics
    from reportlab.pdfbase.cidfonts import UnicodeCIDFont
    from reportlab.pdfbase.ttfonts import TTFont
    from reportlab.platypus import Image as RLImage, Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle

    font_path = os.environ.get("PDF_FONT", "/System/Library/Fonts/Supplemental/Arial Unicode.ttf")
    if os.path.isfile(font_path):
        font = "SafePathCJK"
        pdfmetrics.registerFont(TTFont(font, font_path))
    else:
        font = "MSung-Light"
        pdfmetrics.registerFont(UnicodeCIDFont(font))
    body = ParagraphStyle("b", fontName=font, fontSize=10, leading=15, wordWrap="CJK")
    small = ParagraphStyle("s", parent=body, fontSize=8, leading=12, textColor=colors.grey)
    head = ParagraphStyle("h", parent=body, fontSize=13, leading=20, spaceBefore=10, spaceAfter=4)
    title = ParagraphStyle("t", parent=body, fontSize=18, leading=26, spaceAfter=6)
    warn = ParagraphStyle("w", parent=body, textColor=colors.HexColor("#b00020"))

    spec = json.loads(Path(args.spec).read_text())
    story = [Paragraph(spec["title"], title)]
    for line in spec.get("warnings", []):
        story.append(Paragraph(line, warn))
    story.append(Spacer(1, 6))
    for label, value in spec["applicant"]:
        story.append(Paragraph(f"{label}：{value}", body))

    story.append(Paragraph("一、申請品項（草稿）", head))
    rows = [[Paragraph(h, body) for h in ["代碼", "品項", "位置／量測", "給付上限（元）", "自付試算（元）"]]]
    for r in spec["items"]:
        rows.append([Paragraph(str(c), body) for c in r])
    table = Table(rows, colWidths=[1.5 * cm, 6.2 * cm, 4.6 * cm, 2.2 * cm, 2.2 * cm], repeatRows=1)
    table.setStyle(TableStyle([
        ("GRID", (0, 0), (-1, -1), 0.5, colors.grey),
        ("BACKGROUND", (0, 0), (-1, 0), colors.whitesmoke),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
    ]))
    story.append(table)
    story.append(Spacer(1, 6))
    for line in spec.get("totals", []):
        story.append(Paragraph(line, body))

    story.append(Paragraph("二、現場證據", head))
    for e in spec.get("evidence", []):
        with Image.open(e["path"]) as im:
            ratio = im.size[1] / im.size[0]
        story.append(Paragraph(e["caption"], body))
        story.append(RLImage(e["path"], width=14 * cm, height=14 * cm * ratio))
        story.append(Spacer(1, 6))

    story.append(Paragraph("三、申請前檢查清單", head))
    for line in spec.get("checklist", []):
        story.append(Paragraph("□ " + line, body))
    story.append(Paragraph("四、法規依據", head))
    for line in spec.get("citations", []):
        story.append(Paragraph(line, small))
    SimpleDocTemplate(args.out, pagesize=A4, leftMargin=1.6 * cm, rightMargin=1.6 * cm, topMargin=1.5 * cm, bottomMargin=1.5 * cm).build(story)
    return {"out": args.out}


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
    w = sub.add_parser("path")
    w.add_argument("--image", required=True)
    w.add_argument("--floor", required=True)
    w.add_argument("--doorway")
    w.add_argument("--line", help="user-drawn walking line: x1,y1,x2,y2,...")
    w.add_argument("--objects", nargs="+", default=[])
    w.add_argument("--min-overlap", type=float, default=0.15)
    w.add_argument("--out")
    w.add_argument("--corridor-out")
    b = sub.add_parser("blur")
    b.add_argument("--image", required=True)
    b.add_argument("--objects", nargs="*", default=[])
    b.add_argument("--out", required=True)
    n = sub.add_parser("annotate")
    n.add_argument("--image", required=True)
    n.add_argument("--spec", required=True)
    n.add_argument("--out", required=True)
    d = sub.add_parser("pdf")
    d.add_argument("--spec", required=True)
    d.add_argument("--out", required=True)
    args = ap.parse_args()
    handlers = {"parse": parse, "overlay": overlay, "measure": measure, "path": path, "blur": blur, "annotate": annotate, "pdf": pdf}
    result = handlers[args.cmd](args)
    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
