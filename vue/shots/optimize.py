"""Optimizes the showcase PNGs in landing/shots/ and records their sizes in manifest.json.

    python shots/optimize.py [--limit 350]

Every PNG is first saved as an optimized true-color PNG (alpha dropped: the shots are opaque);
one still above the limit (KiB) is quantized to a 256-color palette with Floyd-Steinberg
dithering, which halves the size of a console screenshot without visible loss. Needs Pillow.
"""
import argparse
import json
import sys
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
SHOTS = ROOT / "landing" / "shots"


def optimize(png: Path, limit: int) -> tuple[int, int, str]:
    before = png.stat().st_size
    image = Image.open(png).convert("RGB")
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
    return before, png.stat().st_size, method


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
    total = 0
    over = []
    for png in pngs:
        before, after, method = optimize(png, limit)
        sizes[png.name] = after
        total += after
        flag = "" if after <= limit else "  OVER LIMIT"
        if after > limit:
            over.append(png.name)
        print(f"{png.name:34} {before / 1024:7.0f} KiB -> {after / 1024:6.0f} KiB  {method}{flag}")

    manifest_path = SHOTS / "manifest.json"
    if manifest_path.exists():
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        for scene in manifest.get("scenes", []):
            scene["sizes_bytes"] = {theme: sizes.get(name) for theme, name in (scene.get("theme_files") or {}).items()}
        manifest["optimized"] = {"tool": "Pillow (optimize + 256-color palette when above the limit)", "limit_bytes": limit}
        manifest_path.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8", newline="\n")
    print(f"\n{len(pngs)} files, {total / 1024:.0f} KiB total" + (f", {len(over)} over the limit: {', '.join(over)}" if over else ""))
    return 0


if __name__ == "__main__":
    sys.exit(main())
