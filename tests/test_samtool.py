"""Measurement math in helpers/samtool. Run: helpers/samtool/.venv/bin/python -m unittest tests/test_samtool.py"""
import os
import sys
import tempfile
import unittest
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "helpers" / "samtool"))
import samtool  # noqa: E402


class FullMask(unittest.TestCase):
    def test_places_local_mask_at_its_bounds(self):
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / "m.png"
            Image.fromarray(np.full((5, 5), 255, dtype=np.uint8)).save(path)
            mask = samtool._full_mask({"mask": str(path), "bounds": [10, 20, 30, 40]}, (100, 80))
            self.assertEqual(mask.shape, (80, 100))
            self.assertEqual(int(mask.sum()), 400)
            self.assertTrue(mask[20, 10] and mask[39, 29])
            self.assertFalse(mask[19, 10] or mask[40, 29])

    def test_mask_past_the_frame_edge_is_clipped(self):
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / "m.png"
            Image.fromarray(np.full((5, 5), 255, dtype=np.uint8)).save(path)
            mask = samtool._full_mask({"mask": str(path), "bounds": [90, 70, 110, 90]}, (100, 80))
            self.assertEqual(int(mask.sum()), 100)


class PrincipalExtent(unittest.TestCase):
    def test_upright_a4_gives_the_right_scale_and_aspect(self):
        mask = np.zeros((400, 300), dtype=bool)
        mask[50:347, 50:260] = True  # 297 × 210 px: A4 at 10 px/cm
        long_px, short_px = samtool._principal_extent(mask)
        self.assertAlmostEqual(long_px / 29.7, 10, delta=0.1)
        self.assertAlmostEqual(short_px / 21.0, 10, delta=0.1)

    def test_rotation_does_not_change_the_extent(self):
        image = Image.new("L", (600, 600), 0)
        image.paste(255, (150, 200, 450, 400))
        mask = np.asarray(image.rotate(30)) > 127
        long_px, short_px = samtool._principal_extent(mask)
        self.assertAlmostEqual(long_px, 300, delta=6)
        self.assertAlmostEqual(short_px, 200, delta=6)


class Corridor(unittest.TestCase):
    def setUp(self):
        self.floor = np.zeros((100, 200), dtype=bool)
        self.floor[50:, :] = True

    def test_stays_on_the_floor_and_reaches_the_door(self):
        door = {"bounds": [90, 0, 110, 50]}
        corridor = samtool._corridor((200, 100), self.floor, door, None)
        self.assertFalse(corridor[:50].any())
        self.assertTrue(corridor[51, 100])
        self.assertTrue(corridor[99, 100])
        self.assertFalse(corridor[51, 10])

    def test_object_sitting_on_the_floor_is_still_inside_the_corridor(self):
        floor = self.floor.copy()
        floor[70:80, 95:105] = False  # an object hides this part of the floor
        corridor = samtool._corridor((200, 100), floor, {"bounds": [90, 0, 110, 50]}, None)
        self.assertTrue(corridor[75, 100])

    def test_user_drawn_line_replaces_the_default(self):
        corridor = samtool._corridor((200, 100), self.floor, None, [10, 60, 10, 99])
        self.assertTrue(corridor[80, 10])
        self.assertFalse(corridor[80, 150])


class ReferenceCheck(unittest.TestCase):
    """A4 plausibility: the kinds of false sheet seen in a real walkthrough must not set a scale."""

    def image(self, fill=(240, 240, 238), box=(400, 300, 610, 597), size=(1440, 1920)):
        img = Image.new("RGB", size, (60, 55, 50))
        ImageDraw.Draw(img).rectangle(box, fill=fill)
        mask = np.zeros((size[1], size[0]), dtype=bool)
        mask[box[1]:box[3] + 1, box[0]:box[2] + 1] = True
        return img, mask

    def test_a_flat_white_a4_passes_with_the_right_scale(self):
        check = samtool._check_reference(*self.image())  # 211 x 298 px: A4 at 10 px/cm
        self.assertTrue(check["usable"], check["reasons"])
        self.assertAlmostEqual(check["px_per_cm"], 10, delta=0.2)

    def test_small_far_blob_like_scan_7_is_rejected(self):
        # About 3.4 px/cm: an A4-shaped white patch only 70 x 100 px.
        check = samtool._check_reference(*self.image(box=(400, 300, 470, 400)))
        self.assertFalse(check["usable"])
        self.assertTrue(any("covers only" in r for r in check["reasons"]))
        self.assertTrue(any("implausible scale" in r for r in check["reasons"]))

    def test_grey_or_coloured_patch_is_rejected(self):
        self.assertFalse(samtool._check_reference(*self.image(fill=(120, 120, 120)))["usable"])
        self.assertFalse(samtool._check_reference(*self.image(fill=(240, 200, 60)))["usable"])

    def test_wrong_shape_is_rejected(self):
        check = samtool._check_reference(*self.image(box=(400, 300, 700, 400)))  # 3:1, not A4
        self.assertFalse(check["usable"])
        self.assertTrue(any("off A4" in r for r in check["reasons"]))


class LongestRun(unittest.TestCase):
    def test_counts_consecutive_frames_only(self):
        self.assertEqual(samtool._longest_run([5]), 1)
        self.assertEqual(samtool._longest_run([1, 2, 3, 7, 8]), 3)
        self.assertEqual(samtool._longest_run([9, 3, 4, 3]), 2)
        self.assertEqual(samtool._longest_run([]), 0)


class PdfFont(unittest.TestCase):
    def setUp(self):
        from reportlab.pdfbase import pdfmetrics
        from reportlab.pdfbase.cidfonts import UnicodeCIDFont
        from reportlab.pdfbase.ttfonts import TTFont
        self.modules = (pdfmetrics, TTFont, UnicodeCIDFont)
        self.saved = os.environ.pop("PDF_FONT", None)

    def tearDown(self):
        os.environ.pop("PDF_FONT", None)
        if self.saved is not None:
            os.environ["PDF_FONT"] = self.saved

    def test_missing_explicit_font_fails_loudly(self):
        os.environ["PDF_FONT"] = "/nonexistent/font.ttf"
        with self.assertRaises(SystemExit) as error:
            samtool._register_pdf_font(*self.modules)
        self.assertIn("file not found", str(error.exception))

    def test_unusable_font_fails_with_the_reason(self):
        with tempfile.NamedTemporaryFile(suffix=".ttf", delete=False) as bad:
            bad.write(b"OTTO not a truetype font")
        os.environ["PDF_FONT"] = bad.name
        try:
            with self.assertRaises(SystemExit) as error:
                samtool._register_pdf_font(*self.modules)
            self.assertIn("TrueType", str(error.exception))
        finally:
            os.unlink(bad.name)


if __name__ == "__main__":
    unittest.main()
