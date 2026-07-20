#!/usr/bin/env python3
"""Build monochrome WOFF/WOFF2 icon fonts from Glyph's sanitized SVG paths."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path
from xml.etree import ElementTree

from fontTools.fontBuilder import FontBuilder
from fontTools.pens.t2CharStringPen import T2CharStringPen
from fontTools.svgLib.path import SVGPath


UNITS_PER_EM = 1000
ASCENT = 900
DESCENT = -100
DRAWING_SIZE = 800


def local_name(tag: str) -> str:
    return tag.rsplit("}", 1)[-1].lower()


def inspect_icon(icon: dict) -> list[str]:
    issues: list[str] = []
    try:
        root = ElementTree.fromstring(f'<svg xmlns="http://www.w3.org/2000/svg">{icon["symbol_markup"]}</svg>')
    except ElementTree.ParseError:
        return ["Contains invalid SVG markup."]

    path_count = 0
    for element in root.iter():
        name = local_name(element.tag)
        if name in {"title", "desc"}:
            continue
        if name not in {"svg", "g", "path"}:
            issues.append(f"Contains unsupported <{name}> geometry; convert it to a path first.")
            continue
        stroke = element.attrib.get("stroke", "").strip().lower()
        if stroke and stroke != "none":
            issues.append("Contains stroked paths; expand strokes to outlines first.")
        if element.attrib.get("fill-rule", "").strip().lower() == "evenodd":
            issues.append("Uses even-odd fills; convert contours to non-zero winding first.")
        for opacity_name in ("opacity", "fill-opacity", "stroke-opacity"):
            opacity = element.attrib.get(opacity_name)
            if opacity not in (None, "", "1", "1.0"):
                issues.append("Uses opacity, which a monochrome icon font cannot preserve.")

        if name != "path":
            continue
        path_count += 1
        if not element.attrib.get("d", "").strip():
            issues.append("Contains an empty path.")
        if element.attrib.get("fill", "").strip().lower() == "none":
            issues.append("Contains an unfilled path; expand its stroke to an outline first.")

    if path_count == 0 and not issues:
        issues.append("Does not contain a path outline.")
    return list(dict.fromkeys(issues))


def parse_view_box(value: str) -> tuple[float, float, float, float]:
    numbers = [float(item) for item in re.split(r"\s+", value.strip())]
    if len(numbers) != 4 or numbers[2] <= 0 or numbers[3] <= 0:
        raise ValueError("Invalid viewBox.")
    return numbers[0], numbers[1], numbers[2], numbers[3]


def build(payload: dict, output_dir: Path) -> None:
    icons = payload.get("icons") or []
    failures = []
    for icon in icons:
        issues = inspect_icon(icon)
        if issues:
            failures.append({"id": icon.get("id"), "symbol_id": icon.get("symbol_id"), "issues": issues})

    if failures:
        raise BuildError({
            "code": "unsupported_geometry",
            "message": f"{len(failures)} icon{'s' if len(failures) != 1 else ''} cannot be converted to font outlines.",
            "icons": failures,
        })

    family = str(payload.get("family") or "Glyph Icons")
    ps_name = re.sub(r"[^A-Za-z0-9-]", "", family.replace(" ", "-")) or "Glyph-Icons"
    glyph_order = [".notdef"]
    cmap: dict[int, str] = {}
    metrics = {".notdef": (UNITS_PER_EM, 0)}
    char_strings = {}

    empty_pen = T2CharStringPen(UNITS_PER_EM, None)
    char_strings[".notdef"] = empty_pen.getCharString()

    for icon in icons:
        glyph_name = f"icon{int(icon['id'])}"
        min_x, min_y, width, height = parse_view_box(str(icon["view_box"]))
        scale = DRAWING_SIZE / max(width, height)
        scaled_width = width * scale
        scaled_height = height * scale
        x_left = (UNITS_PER_EM - scaled_width) / 2
        y_bottom = 100 + (DRAWING_SIZE - scaled_height) / 2
        transform = (
            scale,
            0,
            0,
            -scale,
            x_left - min_x * scale,
            y_bottom + (min_y + height) * scale,
        )
        svg = f'<svg xmlns="http://www.w3.org/2000/svg">{icon["symbol_markup"]}</svg>'
        pen = T2CharStringPen(UNITS_PER_EM, None)
        SVGPath.fromstring(svg, transform=transform).draw(pen)
        char_strings[glyph_name] = pen.getCharString()
        glyph_order.append(glyph_name)
        cmap[int(icon["codepoint"])] = glyph_name
        metrics[glyph_name] = (UNITS_PER_EM, 0)

    builder = FontBuilder(UNITS_PER_EM, isTTF=False)
    builder.setupGlyphOrder(glyph_order)
    builder.setupCharacterMap(cmap)
    builder.setupHorizontalMetrics(metrics)
    builder.setupHorizontalHeader(ascent=ASCENT, descent=DESCENT)
    builder.setupNameTable({
        "familyName": family,
        "styleName": "Regular",
        "uniqueFontIdentifier": f"{family};{payload.get('version', '1')}",
        "fullName": f"{family} Regular",
        "psName": f"{ps_name}-Regular",
        "version": "Version 1.000",
    })
    builder.setupOS2(
        sTypoAscender=ASCENT,
        sTypoDescender=DESCENT,
        usWinAscent=ASCENT,
        usWinDescent=abs(DESCENT),
        sxHeight=0,
        sCapHeight=800,
    )
    builder.setupPost(keepGlyphNames=False)
    builder.setupCFF(
        f"{ps_name}-Regular",
        {"FullName": f"{family} Regular", "FamilyName": family, "Weight": "Regular"},
        char_strings,
        {},
    )

    output_dir.mkdir(parents=True, exist_ok=True)
    builder.font.flavor = "woff2"
    builder.font.save(output_dir / "font.woff2")
    builder.font.flavor = "woff"
    builder.font.save(output_dir / "font.woff")


class BuildError(Exception):
    def __init__(self, error: dict):
        super().__init__(error.get("message", "Font generation failed."))
        self.error = error


def main() -> int:
    try:
        input_path = Path(sys.argv[1])
        output_dir = Path(sys.argv[2])
        payload = json.loads(input_path.read_text(encoding="utf-8"))
        build(payload, output_dir)
        print(json.dumps({"ok": True}))
        return 0
    except BuildError as exc:
        print(json.dumps({"ok": False, "error": exc.error}))
        return 2
    except Exception as exc:  # Keep implementation details out of the browser response.
        print(json.dumps({"ok": False, "error": {"code": "generation_failed", "message": str(exc)}}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
