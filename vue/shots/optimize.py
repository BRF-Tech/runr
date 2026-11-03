"""Optimizes the showcase PNGs in landing/shots/, writes their WebP copies and records the files
and sizes in manifest.json. The step after `npm run shots`, run after every render.

    python shots/optimize.py [--limit 350]

Every PNG is first saved as an optimized true-color PNG (alpha dropped: the shots are opaque);
one still above the limit (KiB) is quantized to a 256-color palette with Floyd-Steinberg
dithering, which halves the size of a console screenshot without visible loss. The PNG stays for
the README. From the true-color image (before any quantizing) every shot is also written as WebP
at its full width and at 720 px (<name>-1440.webp, <name>-720.webp), each the smaller of lossless
and lossy (quality 80) WebP, for the landing's <picture> elements. Needs Pillow with WebP.
"""
import argparse
import json
import sys
from io import BytesIO
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
SHOTS = ROOT / "landing" / "shots"
WEBP_WIDTHS = (None, 720)  # None: the shot's own width (1440)
WEBP_QUALITY = 80


def webp(image: Image.Image, png: Path) -> dict[str, tuple[str, int]]:
    """<name>-<width>.webp for every width of WEBP_WIDTHS: {width: (file name, bytes)}."""
    out = {}
    for width in WEBP_WIDTHS:
        img = image
        if width and image.width > width:
            img = image.resize((width, round(image.height * width / image.width)), Image.Resampling.LANCZOS)
        target = png.with_name(f"{png.stem}-{img.width}.webp")
        lossy, lossless = BytesIO(), BytesIO()
        img.save(lossy, format="WEBP", quality=WEBP_QUALITY, method=6)
        img.save(lossless, format="WEBP", lossless=True, method=6)
        data = min(lossy.getvalue(), lossless.getvalue(), key=len)
        target.write_bytes(data)
        out[str(img.width)] = (target.name, len(data))
    return out


def optimize(png: Path, limit: int) -> tuple[int, int, str, dict[str, tuple[str, int]]]:
    before = png.stat().st_size
    image = Image.open(png).convert("RGB")
    webps = webp(image, png)
    tmp = png.with_suffix(".tmp.png")
    image.save(tmp, format="PNG", optimize=True)
    method = "truecolor"
    if tmp.stat().st_size > limit:
        palette = image.quantize(colors=256, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.FLOYDSTEINBERG)
        palette.save(tmp, format="PNG", optimize=True)
        method = "palette-256"
    if tmp.stat().st_size < before:
        tmp.replace(png)
    else:
        tmp.unlink()
        method = "kept"
    return before, png.stat().st_size, method, webps


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--limit", type=int, default=350, help="target size per file in KiB")
    args = parser.parse_args()
    limit = args.limit * 1024

    pngs = sorted(SHOTS.glob("*.png"))
    if not pngs:
        print(f"no PNG in {SHOTS}", file=sys.stderr)
        return 1
    sizes: dict[str, int] = {}
    webps: dict[str, dict[str, tuple[str, int]]] = {}
    total = 0
    total_webp = 0
    over = []
    for png in pngs:
        before, after, method, webps[png.name] = optimize(png, limit)
        sizes[png.name] = after
        total += after
        total_webp += sum(n for _, n in webps[png.name].values())
        flag = "" if after <= limit else "  OVER LIMIT"
        if after > limit:
            over.append(png.name)
        webp_note = ", ".join(f"{w}w {n / 1024:.0f}" for w, (_, n) in webps[png.name].items())
        print(f"{png.name:34} {before / 1024:7.0f} KiB -> {after / 1024:6.0f} KiB  {method}{flag}  (WebP KiB: {webp_note})")

    manifest_path = SHOTS / "manifest.json"
    if manifest_path.exists():
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        for scene in manifest.get("scenes", []):
            files = scene.get("theme_files") or {}
            scene["sizes_bytes"] = {theme: sizes.get(name) for theme, name in files.items()}
            scene["webp_files"] = {theme: {w: f for w, (f, _) in webps.get(name, {}).items()} for theme, name in files.items()}
            scene["webp_sizes_bytes"] = {theme: {w: n for w, (_, n) in webps.get(name, {}).items()} for theme, name in files.items()}
        manifest["optimized"] = {
            "tool": "Pillow (optimize + 256-color palette when above the limit)",
            "limit_bytes": limit,
            "webp": {
                "tool": f"Pillow WebP, method 6, the smaller of lossless and quality {WEBP_QUALITY}",
                "widths": ["full"] + [w for w in WEBP_WIDTHS if w],
            },
        }
        manifest_path.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8", newline="\n")
    print(f"\n{len(pngs)} files, {total / 1024:.0f} KiB total" + (f", {len(over)} over the limit: {', '.join(over)}" if over else "")
          + f"; WebP {total_webp / 1024:.0f} KiB")
    return 0


if __name__ == "__main__":
    sys.exit(main())
