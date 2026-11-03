#!/usr/bin/env python3
"""Fails when the runr.sh site uses a blue-tinted colour: the site is neutral black and gray with
the RunR orange and red as its only accents.

    python3 ci/runr-sh/check-colors.py SITE_DIR

It reads the colours the site applies: every stylesheet (*.css); in every page (*.html) its
<style> blocks, style="" attributes, the SVG paint attributes (fill, stroke, stop-color, color,
flood-color) and the theme-color meta; and the two canvas scripts (js/bg.js, js/hero3d.js; their
comments left out). Text of the pages (a hex value quoted in the documentation) does not count.
A colour fails when its blue channel is more than 8 above its red one (hex, 0xRRGGBB, rgb[a](),
hsl[a]() with numbers), unless ALLOWED names it. Only the standard library is used.
"""
import colorsys
import os
import re
import sys

# Kept on purpose: greens, which are not blue tones but pass blue > red + 8.
ALLOWED = {
    "#22c55e": "green: the license badge colour and the 'yes' marks of the comparison",
    "#4ade80": "green: 'yes' cells of the comparison table, RunR's own column, the third window dot",
    "rgb(74,222,128)": "the same green (#4ade80) with alpha",
    "#86efac": "light green: text on the green 'yes' badges",
    "#42b883": "the Vue logo green in the architecture diagram",
}

HEX = re.compile(r"(?<![\w&])#([0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})(?![\w-])")
HEX0X = re.compile(r"\b0x([0-9a-fA-F]{6})\b")
RGB = re.compile(r"rgba?\(\s*(\d+)\s*[, ]\s*(\d+)\s*[, ]\s*(\d+)")
HSL = re.compile(r"hsla?\(\s*(-?[\d.]+)(?:deg)?\s*[, ]\s*([\d.]+)%\s*[, ]\s*([\d.]+)%")
STYLE_BLOCK = re.compile(r"<style\b[^>]*>(.*?)</style>", re.S | re.I)
STYLE_ATTR = re.compile(r'\sstyle\s*=\s*"([^"]*)"|\sstyle\s*=\s*\'([^\']*)\'', re.I)
PAINT_ATTR = re.compile(r'\s(?:fill|stroke|stop-color|flood-color|color|bgcolor)\s*=\s*"([^"]*)"', re.I)
THEME = re.compile(r'<meta\s+name="theme-color"\s+content="([^"]*)"', re.I)
JS_COMMENTS = re.compile(r"/\*.*?\*/|(?<![:\\'\"])//[^\n]*", re.S)
CANVAS_SCRIPTS = ("js/bg.js", "js/hero3d.js")


def colours(text):
    """(name, (r, g, b)) of every colour literal in text."""
    for m in HEX.finditer(text):
        h = m.group(1)
        full = "".join(c * 2 for c in h[:3]) if len(h) in (3, 4) else h[:6]
        yield "#" + h.lower(), tuple(int(full[i:i + 2], 16) for i in (0, 2, 4))
    for m in HEX0X.finditer(text):
        yield "0x" + m.group(1).lower(), tuple(int(m.group(1)[i:i + 2], 16) for i in (0, 2, 4))
    for m in RGB.finditer(text):
        rgb = tuple(int(x) for x in m.groups())
        yield "rgb(%d,%d,%d)" % rgb, rgb
    for m in HSL.finditer(text):
        h, s, l = float(m.group(1)) % 360 / 360, float(m.group(2)) / 100, float(m.group(3)) / 100
        r, g, b = colorsys.hls_to_rgb(h, l, s)
        yield "hsl(%s,%s%%,%s%%)" % m.groups(), (round(r * 255), round(g * 255), round(b * 255))


def applied(rel, text):
    """The parts of a file that carry colours the site applies."""
    if rel.endswith(".css"):
        return [text]
    if rel.endswith(".js"):
        return [JS_COMMENTS.sub("", text)]
    parts = STYLE_BLOCK.findall(text) + THEME.findall(text) + PAINT_ATTR.findall(text)
    parts += [a or b for a, b in STYLE_ATTR.findall(text)]
    return parts


def main():
    root = sys.argv[1]
    bad, checked = [], 0
    for dirpath, _, files in os.walk(root):
        for name in sorted(files):
            full = os.path.join(dirpath, name)
            rel = os.path.relpath(full, root).replace(os.sep, "/")
            if not (rel.endswith((".css", ".html")) or rel in CANVAS_SCRIPTS):
                continue
            with open(full, encoding="utf-8", errors="replace") as f:
                text = f.read()
            for part in applied(rel, text):
                for name_, (r, g, b) in colours(part):
                    checked += 1
                    if b > r + 8 and name_ not in ALLOWED:
                        bad.append("%s: %s = rgb(%d, %d, %d)" % (rel, name_, r, g, b))
    for line in sorted(set(bad)):
        print("FAIL blue-tinted colour " + line)
    print("colours: %d checked, %d blue-tinted%s" % (checked, len(set(bad)),
          "" if bad else " (allowed: %s)" % ", ".join(sorted(ALLOWED))))
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
