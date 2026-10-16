#!/usr/bin/env python3
"""
SureOrder — asset generator.

Regenerates every logo asset from a single master logo (assets/logo.png).
Usage:  python3 tools/generate-assets.py

Requires Pillow (pip install pillow).

Quality rules (per Pillow docs via Context7):
  - resize(..., resample=LANCZOS, reducing_gap=3.0) — stepwise box-filter
    downscaling; far cleaner than a single LANCZOS pass for large reductions
  - save(..., optimize=True, dpi=(96, 96)) — minimal PNG size + pHYs DPI chunk
  - aspect ratio is always preserved; no non-uniform scaling (the original
    banner bug)
"""
from __future__ import annotations

import os
import sys
from typing import Iterator, Tuple

from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ASSETS = os.path.join(ROOT, "assets")
WPORG = os.path.join(ROOT, ".wordpress-org")
ADMIN_IMAGES = os.path.join(ROOT, "admin", "img")
MASTER = os.path.join(ASSETS, "logo.png")

NAVY = (29, 59, 95)      # #1d3b5f
TEAL = (44, 169, 165)    # #2ca9a5
DPI = (96, 96)
RGB = Tuple[int, int, int]


def save_png(im: Image.Image, path: str) -> None:
    im.save(path, "PNG", optimize=True, dpi=DPI)
    print(f"  wrote {path} ({im.width}x{im.height}, {os.path.getsize(path) / 1024:.0f} KB)")


def find_font(name: str, size: int) -> ImageFont.FreeTypeFont:
    for d in ("/System/Library/Fonts/Supplemental", "/System/Library/Fonts"):
        p = os.path.join(d, name)
        try:
            return ImageFont.truetype(p, size)
        except OSError:
            continue  # try next font location
    return ImageFont.truetype("/System/Library/Fonts/Helvetica.ttc", size, index=0)


def pixels(im: Image.Image) -> Iterator[Tuple[int, int, RGB]]:
    """Typed, reasonably-fast pixel iterator over an RGB image."""
    for y in range(im.height):
        for x in range(im.width):
            yield x, y, im.getpixel((x, y))  # type: ignore[arg-type]


def content_bbox(im: Image.Image, threshold: int = 40) -> Tuple[int, int, int, int]:
    """Bounding box of non-background (non-white) pixels."""
    w, h = im.size
    minx, miny, maxx, maxy = w, h, -1, -1
    for x, y, (r, g, b) in pixels(im):
        if abs(r - 255) + abs(g - 255) + abs(b - 255) > threshold:
            minx = min(minx, x)
            maxx = max(maxx, x)
            miny = min(miny, y)
            maxy = max(maxy, y)
    return minx, miny, maxx, maxy


def split_lockup(src: Image.Image) -> Tuple[Image.Image, Image.Image]:
    """Split the stacked lockup into (shield, wordmark) via row gaps."""
    minx, miny, maxx, maxy = content_bbox(src)
    rows: dict[int, int] = {}
    for y in range(miny, maxy + 1):
        n = 0
        for x in range(minx, maxx + 1):
            r, g, b = src.getpixel((x, y))  # type: ignore[arg-type]
            if abs(r - 255) + abs(g - 255) + abs(b - 255) > 40:
                n += 1
        if n:
            rows[y] = n
    ys = sorted(rows)
    for i in range(len(ys) - 1):
        if ys[i + 1] - ys[i] > 4:  # first deep gap = shield | wordmark split
            shield = src.crop((minx, miny, maxx, ys[i] + 1))
            wordmark = src.crop((minx, ys[i + 1] - 1, maxx, maxy))
            # tight-crop each component to its OWN visual content — the raw
            # crops inherit the full lockup width and carry internal white
            # padding, which pushes the composed group off-center (banner bug)
            b = content_bbox(shield)
            shield = shield.crop(b)
            b = content_bbox(wordmark)
            wordmark = wordmark.crop(b)
            return shield, wordmark
    raise RuntimeError("could not split lockup into shield + wordmark")


def fit(im: Image.Image, box: int) -> Image.Image:
    """Proportionally fit im into a box x box, returning a new image."""
    scale = box / max(im.size)
    nw, nh = round(im.width * scale), round(im.height * scale)
    return im.resize((nw, nh), Image.Resampling.LANCZOS, reducing_gap=3.0)


def main() -> None:
    src = Image.open(MASTER).convert("RGB")
    print(f"master: {src.width}x{src.height}")

    shield, wordmark = split_lockup(src)
    print(f"components: shield {shield.size}, wordmark {wordmark.size}")

    # ---- wp.org source copy ----
    try:
        os.makedirs(WPORG, exist_ok=True)
        os.makedirs(ADMIN_IMAGES, exist_ok=True)
    except OSError as exc:
        sys.exit(f"cannot create asset directory: {exc}")
    save_png(src, os.path.join(WPORG, "cod-shield-logo.png"))

    # ---- social preview 1280x640 ----
    W, H = 1280, 640
    canvas = Image.new("RGB", (W, H), "white")
    draw = ImageDraw.Draw(canvas)
    bx = content_bbox(src)
    lockup = src.crop((bx[0] - 10, bx[1] - 10, bx[2] + 10, bx[3] + 10))
    target_h = 348
    lw = round(lockup.width * target_h / lockup.height)
    lr = lockup.resize((lw, target_h), Image.Resampling.LANCZOS, reducing_gap=3.0)
    canvas.paste(lr, ((W - lw) // 2, 84))
    draw.rounded_rectangle([(W - 96) // 2, 462, (W + 96) // 2, 468], radius=3, fill=TEAL)
    tagline = "Reduce COD RTO on your WooCommerce store"
    ft = find_font("Arial Bold.ttf", 38)
    tb = draw.textbbox((0, 0), tagline, font=ft)
    draw.text(((W - (tb[2] - tb[0])) // 2, 498), tagline, font=ft, fill=NAVY)
    save_png(canvas, os.path.join(ASSETS, "social-preview.png"))

    # ---- banners 1544x500 (shield left, wordmark right) ----
    W, H = 1544, 500
    banner = Image.new("RGB", (W, H), "white")
    sh = 400
    sw = round(shield.width * sh / shield.height)
    sr = shield.resize((sw, sh), Image.Resampling.LANCZOS, reducing_gap=3.0)
    th = 198
    tw = round(wordmark.width * th / wordmark.height)
    tr = wordmark.resize((tw, th), Image.Resampling.LANCZOS, reducing_gap=3.0)
    gap = 30
    left = (W - sw - tw - gap) // 2
    banner.paste(sr, (left, (H - sh) // 2))
    banner.paste(tr, (left + sw + gap, (H - th) // 2))
    save_png(banner, os.path.join(WPORG, "banner-1544x500.png"))
    small = banner.resize((772, 250), Image.Resampling.LANCZOS, reducing_gap=3.0)
    save_png(small, os.path.join(WPORG, "banner-772x250.png"))

    # ---- icons: square shield, 13% padding ----
    for size in (256, 128):
        icon = Image.new("RGB", (size, size), "white")
        pad = size // 8  # ~12.5% padding
        s = fit(shield, size - 2 * pad)
        icon.paste(s, ((size - s.width) // 2, (size - s.height) // 2))
        save_png(icon, os.path.join(WPORG, f"icon-{size}x{size}.png"))
        if size == 128:
            save_png(icon, os.path.join(ADMIN_IMAGES, "icon-128x128.png"))

    print("done.")


if __name__ == "__main__":
    main()
