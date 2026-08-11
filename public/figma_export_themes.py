"""
Export Figma Design One–Four assets into theme-one … theme-four uploads.
Requires FIGMA_TOKEN env var. Does NOT save the token to disk.

Usage:
  FIGMA_TOKEN=figd_... python figma_export_themes.py
"""
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request
from pathlib import Path

FILE_KEY = "9JC8u8JWvxpo7uUOKxqawp"
BASE = Path(__file__).resolve().parent
UPLOADS = BASE / "uploads"
FIGMA_JSON = BASE / "figma_homes.json"

THEMES = {
    "theme-one": {"home": "940:11462", "design": "940:8545"},
    "theme-two": {"home": "217:6867", "design": "216:6209"},
    "theme-three": {"home": "381:3580", "design": "379:3575"},
    "theme-four": {"home": "566:17354", "design": "10:879"},
}

# Per-theme export plan: (folder, filename, node_id or None for auto-pick)
# Auto-pick uses heuristics from scanned nodes.


def api_get(url: str, token: str) -> dict:
    req = urllib.request.Request(url, headers={"X-Figma-Token": token})
    with urllib.request.urlopen(req, timeout=120) as resp:
        return json.loads(resp.read().decode("utf-8"))


def api_download(url: str, dest: Path):
    dest.parent.mkdir(parents=True, exist_ok=True)
    with urllib.request.urlopen(url, timeout=120) as resp:
        dest.write_bytes(resp.read())


def walk(node, out=None):
    if out is None:
        out = []
    name = node.get("name", "")
    nid = node.get("id", "")
    typ = node.get("type", "")
    fills = node.get("fills") or []
    has_img = any(f.get("type") == "IMAGE" for f in fills if isinstance(f, dict))
    bb = node.get("absoluteBoundingBox") or {}
    w, h = bb.get("width", 0), bb.get("height", 0)
    if w < 40 or h < 40:
        pass
    elif has_img or typ in ("RECTANGLE", "ELLIPSE", "FRAME", "COMPONENT"):
        out.append(
            {
                "id": nid,
                "type": typ,
                "name": name,
                "w": w,
                "h": h,
                "area": w * h,
                "has_img": has_img,
            }
        )
    for ch in node.get("children") or []:
        walk(ch, out)
    return out


def classify_nodes(items: list) -> dict:
    """Bucket nodes into banner, category, product, logo, brand, service."""
    buckets = {
        "banner": [],
        "category": [],
        "product": [],
        "logo": [],
        "brand": [],
        "service": [],
        "hero": [],
        "other_img": [],
    }
    for it in items:
        if not it["has_img"]:
            continue
        n = it["name"].lower()
        if "logo" in n:
            buckets["logo"].append(it)
        elif "brand" in n:
            buckets["brand"].append(it)
        elif "service" in n or "icon" in n:
            buckets["service"].append(it)
        elif "banner" in n or "hero" in n or "slider" in n:
            buckets["banner"].append(it)
        elif "category" in n or "categor" in n:
            buckets["category"].append(it)
        elif "product" in n or re.search(r"\b(p\d+|item)\b", n):
            buckets["product"].append(it)
        elif it["w"] > 800 and it["h"] > 200:
            buckets["hero"].append(it)
        elif 120 <= it["w"] <= 400 and 120 <= it["h"] <= 400:
            buckets["category"].append(it)
        elif 150 <= it["w"] <= 500 and 150 <= it["h"] <= 700:
            buckets["product"].append(it)
        else:
            buckets["other_img"].append(it)

    for k in buckets:
        buckets[k].sort(key=lambda x: -x["area"])
    return buckets


def pick_unique(items: list, limit: int) -> list:
    seen = set()
    out = []
    for it in items:
        key = (round(it["w"]), round(it["h"]), it["name"][:20])
        if key in seen:
            continue
        seen.add(key)
        out.append(it)
        if len(out) >= limit:
            break
    return out


def export_nodes(token: str, theme: str, node_ids: list[str], folder: str, prefix: str):
    if not node_ids:
        return []
    ids_param = ",".join(urllib.parse.quote(nid, safe="") for nid in node_ids)
    url = (
        f"https://api.figma.com/v1/images/{FILE_KEY}"
        f"?ids={','.join(node_ids)}&format=png&scale=2"
    )
    data = api_get(url, token)
    images = data.get("images") or {}
    saved = []
    for i, nid in enumerate(node_ids, 1):
        img_url = images.get(nid)
        if not img_url:
            continue
        fname = f"{prefix}{i}.png" if len(node_ids) > 1 else f"{prefix}.png"
        dest = UPLOADS / theme / folder / fname
        api_download(img_url, dest)
        saved.append(str(dest.relative_to(BASE)).replace("\\", "/"))
        time.sleep(0.15)
    return saved


def fetch_home_nodes(token: str) -> dict:
    ids = ",".join(t["home"] for t in THEMES.values())
    url = f"https://api.figma.com/v1/files/{FILE_KEY}/nodes?ids={ids}&depth=8"
    return api_get(url, token)


def main():
    token = os.environ.get("FIGMA_TOKEN")
    if not token:
        print("Set FIGMA_TOKEN environment variable", file=sys.stderr)
        sys.exit(1)

    print("Fetching Figma home pages...")
    data = fetch_home_nodes(token)
    report = {}

    for theme, meta in THEMES.items():
        home_id = meta["home"]
        doc = data.get("nodes", {}).get(home_id, {}).get("document")
        if not doc:
            print(f"WARN: no document for {theme}")
            continue

        items = walk(doc)
        buckets = classify_nodes(items)
        print(f"\n{theme}: {len(items)} nodes, image buckets:", {k: len(v) for k, v in buckets.items()})

        saved = {}
        # Hero/banner — widest images first
        banners = pick_unique(buckets["banner"] + buckets["hero"], 4)
        if not banners:
            banners = pick_unique([x for x in items if x["has_img"] and x["w"] > 600], 4)
        banner_ids = [b["id"] for b in banners]
        for i, nid in enumerate(banner_ids, 1):
            paths = export_nodes(token, theme, [nid], "banner", str(i))
            if paths:
                saved.setdefault("banner", []).append(paths[0])

        cats = pick_unique(buckets["category"], 6)
        if len(cats) < 6:
            cats += pick_unique(buckets["other_img"], 6 - len(cats))
        for i, c in enumerate(cats[:6], 1):
            paths = export_nodes(token, theme, [c["id"]], "category", str(i))

        prods = pick_unique(buckets["product"], 40)
        if len(prods) < 10:
            prods += pick_unique(
                [x for x in items if x["has_img"] and 100 < x["w"] < 600],
                40 - len(prods),
            )
        for i, p in enumerate(prods[:40], 1):
            export_nodes(token, theme, [p["id"]], "product", str(i))

        logos = pick_unique(buckets["logo"], 1)
        if logos:
            export_nodes(token, theme, [logos[0]["id"]], "logo", "logo")

        brands = pick_unique(buckets["brand"], 8)
        for i, b in enumerate(brands[:8], 1):
            export_nodes(token, theme, [b["id"]], "brand", str(i))

        services = pick_unique(buckets["service"], 4)
        for i, s in enumerate(services[:4], 1):
            export_nodes(token, theme, [s["id"]], "service", str(i))

        report[theme] = {
            "banners": len(banner_ids),
            "categories": min(6, len(cats)),
            "products": min(40, len(prods)),
        }

    (BASE / "figma_export_report.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
    print("\nDone. Report:", report)
    print("Images saved under public/uploads/theme-*/")


if __name__ == "__main__":
    main()
