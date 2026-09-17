from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

from fontTools.pens.boundsPen import BoundsPen
from fontTools.ttLib import TTFont


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("build_icon_font", ROOT / "bin" / "build_icon_font.py")
assert SPEC is not None and SPEC.loader is not None
build_icon_font = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(build_icon_font)


class IconFontBuilderTest(unittest.TestCase):
    def build_font(self, icons: list[dict]) -> TTFont:
        output_dir = ROOT / "tmp" / "font-tests"
        output_dir.mkdir(parents=True, exist_ok=True)
        build_icon_font.build({"family": "Test Icons", "icons": icons}, output_dir)
        return TTFont(output_dir / "font.woff2")

    @staticmethod
    def icon(
        path: str,
        *,
        view_box: str = "0 0 24 24",
        icon_id: int = 1,
        codepoint: int = 0xE001,
    ) -> dict:
        return {
            "id": icon_id,
            "symbol_id": f"icon-{icon_id}",
            "codepoint": codepoint,
            "view_box": view_box,
            "symbol_markup": f'<path d="{path}"/>',
        }

    @staticmethod
    def bounds(font: TTFont, codepoint: int = 0xE001) -> tuple[float, float, float, float]:
        glyph_name = font.getBestCmap()[codepoint]
        glyph_set = font.getGlyphSet()
        pen = BoundsPen(glyph_set)
        glyph_set[glyph_name].draw(pen)
        assert pen.bounds is not None
        return pen.bounds

    def test_vertical_metrics_span_one_em_and_center_at_400(self) -> None:
        font = self.build_font([self.icon("M0 0H24V24H0Z")])

        self.assertEqual((font["hhea"].ascent, font["hhea"].descent), (900, -100))
        self.assertEqual((font["OS/2"].sTypoAscender, font["OS/2"].sTypoDescender), (900, -100))
        self.assertEqual((font["OS/2"].usWinAscent, font["OS/2"].usWinDescent), (900, 100))
        x_min, y_min, x_max, y_max = self.bounds(font)
        self.assertAlmostEqual((y_min + y_max) / 2, 400, delta=1)
        self.assertAlmostEqual(y_min, 0, delta=1)
        self.assertAlmostEqual(y_max, 800, delta=1)

    def test_outline_is_centered_horizontally_in_advance_width(self) -> None:
        font = self.build_font([self.icon("M0 0H24V24H0Z")])

        glyph_name = font.getBestCmap()[0xE001]
        x_min, _, x_max, _ = self.bounds(font)
        self.assertEqual(font["hmtx"].metrics[glyph_name], (1000, 100))
        self.assertAlmostEqual((x_min + x_max) / 2, 500, delta=1)
        self.assertAlmostEqual(x_min, 100, delta=1)
        self.assertAlmostEqual(x_max, 900, delta=1)

    def test_asymmetric_viewbox_whitespace_does_not_offset_outline(self) -> None:
        font = self.build_font([self.icon("M2 2H18V18H2Z")])

        x_min, y_min, x_max, y_max = self.bounds(font)
        self.assertAlmostEqual((x_min + x_max) / 2, 500, delta=1)
        self.assertAlmostEqual((y_min + y_max) / 2, 400, delta=1)

    def test_viewbox_padding_still_controls_scale(self) -> None:
        font = self.build_font([self.icon("M4 4H20V20H4Z")])

        x_min, y_min, x_max, y_max = self.bounds(font)
        self.assertAlmostEqual(x_max - x_min, 800 * 16 / 24, delta=1)
        self.assertAlmostEqual(y_max - y_min, 800 * 16 / 24, delta=1)

    def test_non_square_icon_is_centered_without_stretching(self) -> None:
        font = self.build_font([self.icon("M0 0H16V8H0Z", view_box="0 0 16 8")])

        x_min, y_min, x_max, y_max = self.bounds(font)
        self.assertAlmostEqual((x_min + x_max) / 2, 500, delta=1)
        self.assertAlmostEqual((y_min + y_max) / 2, 400, delta=1)
        self.assertAlmostEqual(x_max - x_min, 800, delta=1)
        self.assertAlmostEqual(y_max - y_min, 400, delta=1)


if __name__ == "__main__":
    unittest.main()
