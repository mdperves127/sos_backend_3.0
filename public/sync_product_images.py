"""
Remap product.json image paths to cycle through exported Figma product images.
Usage: python sync_product_images.py [theme-one theme-two ...]
"""
import json
import json
import re
import sys
from pathlib import Path

BASE = Path(__file__).resolve().parent
UPLOADS = BASE / "uploads"
CONTENT = BASE / "theme-content"


MAP_FILE = BASE / "figma_node_map.json"


def product_image_limit(theme: str) -> int | None:
    if not MAP_FILE.exists():
        return None
    spec = json.loads(MAP_FILE.read_text(encoding="utf-8")).get(theme, {})
    prods = spec.get("products") or []
    return len(prods) if prods else None


def product_images(theme: str) -> list[str]:
    folder = UPLOADS / theme / "product"
    if not folder.exists():
        return []
    limit = product_image_limit(theme)
    files = sorted(folder.glob("*.png"), key=lambda p: int(p.stem) if p.stem.isdigit() else p.stem)
    if limit:
        files = [f for f in files if f.stem.isdigit() and int(f.stem) <= limit]
    return [f"uploads/{theme}/product/{f.name}" for f in files]


def sync_theme(theme: str):
    path = CONTENT / theme / "product.json"
    if not path.exists():
        print(f"skip {theme}: no product.json")
        return
    imgs = product_images(theme)
    if not imgs:
        print(f"skip {theme}: no product images")
        return
    text = path.read_text(encoding="utf-8")
    n = 0
    idx = 0

    def repl(m):
        nonlocal n, idx
        n += 1
        path_val = imgs[(idx) % len(imgs)]
        idx += 1
        return f'"image": "{path_val}"'

    new_text = re.sub(r'"image":\s*"uploads/' + theme + r'/product/[^"]+"', repl, text)
    path.write_text(new_text, encoding="utf-8")
    print(f"{theme}: remapped {n} image refs using {len(imgs)} Figma assets")


def main():
    themes = sys.argv[1:] if len(sys.argv) > 1 else [
        "theme-one", "theme-two", "theme-three", "theme-four"
    ]
    for t in themes:
        sync_theme(t)


if __name__ == "__main__":
    main()
