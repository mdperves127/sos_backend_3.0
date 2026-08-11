"""
Export Figma assets using figma_node_map.json → public/uploads/theme-*
Set FIGMA_TOKEN in environment. Token is never written to repo.
"""
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

FILE_KEY = "9JC8u8JWvxpo7uUOKxqawp"
BASE = Path(__file__).resolve().parent
UPLOADS = BASE / "uploads"
MAP = json.loads((BASE / "figma_node_map.json").read_text(encoding="utf-8"))

SECTION_BANNER_COPIES = {
    "theme-one": [(3, 1), (4, 2), (2, 3)],
    "theme-four": [(3, 1), (4, 2)],
}


EXPORT_PLAN = [
    ("banner", "banners", 4),
    ("category", "categories", 6),
    ("product", "products", 40),
    ("logo", "logo", 1),
    ("service", "service", 4),
    ("brand", "brand", 8),
]


def copy_section_banners(theme: str):
    copies = SECTION_BANNER_COPIES.get(theme, [])
    if not copies:
        return
    dest_dir = UPLOADS / theme / "section-banner"
    dest_dir.mkdir(parents=True, exist_ok=True)
    for src_idx, dest_idx in copies:
        src = UPLOADS / theme / "banner" / f"{src_idx}.png"
        dest = dest_dir / f"{dest_idx}.png"
        if src.exists() and (not dest.exists() or dest.stat().st_size < 1000):
            dest.write_bytes(src.read_bytes())
            print(f"    copied section-banner/{dest_idx}.png <- banner/{src_idx}.png", flush=True)


def api_get(url: str, token: str, retries: int = 8) -> dict:
    last_err = None
    for attempt in range(retries):
        try:
            req = urllib.request.Request(url, headers={"X-Figma-Token": token})
            with urllib.request.urlopen(req, timeout=180) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as exc:
            last_err = exc
            if exc.code == 429:
                wait = 30 * (attempt + 1)
                print(f"    rate limited, waiting {wait}s...", flush=True)
                time.sleep(wait)
                continue
            raise
        except Exception as exc:
            last_err = exc
            time.sleep(5 * (attempt + 1))
    raise last_err


def download(url: str, dest: Path, retries: int = 5):
    dest.parent.mkdir(parents=True, exist_ok=True)
    last_err = None
    for attempt in range(retries):
        try:
            with urllib.request.urlopen(url, timeout=300) as resp:
                dest.write_bytes(resp.read())
            return
        except Exception as exc:
            last_err = exc
            time.sleep(2 * (attempt + 1))
    raise last_err


def export_batch(token: str, node_ids: list[str]) -> dict:
    out = {}
    for i in range(0, len(node_ids), 50):
        batch = node_ids[i : i + 50]
        ids = ",".join(batch)
        url = (
            f"https://api.figma.com/v1/images/{FILE_KEY}"
            f"?ids={urllib.parse.quote(ids, safe=':,;')}&format=png&scale=2"
        )
        data = api_get(url, token)
        out.update(data.get("images") or {})
        time.sleep(1.5)
    return out


def export_theme(token: str, theme: str, spec: dict, skip_existing: bool = True):
    print(f"\n=== Exporting {theme} ===", flush=True)
    for folder, key, limit in EXPORT_PLAN:
        ids = spec.get(key, [])[:limit]
        if not ids:
            continue
        print(f"  {folder}: {len(ids)} nodes", flush=True)
        for idx, nid in enumerate(ids, 1):
            fname = "logo.png" if folder == "logo" else f"{idx}.png"
            dest = UPLOADS / theme / folder / fname
            if skip_existing and dest.exists() and dest.stat().st_size > 1000:
                print(f"    skip {folder}/{fname} (exists)", flush=True)
                continue
            urls = export_batch(token, [nid])
            u = urls.get(nid)
            if not u:
                print(f"    SKIP missing url for {nid}", flush=True)
                continue
            download(u, dest)
            print(f"    saved {folder}/{fname} <- {nid[:36]}", flush=True)
            time.sleep(1.0)


def main():
    token = os.environ.get("FIGMA_TOKEN")
    if not token:
        print("ERROR: export FIGMA_TOKEN=your_token", file=sys.stderr)
        sys.exit(1)

    only = [a for a in sys.argv[1:] if not a.startswith("-")]
    force = "--force" in sys.argv
    themes = MAP.items() if not only else [(t, MAP[t]) for t in only if t in MAP]

    for theme, spec in themes:
        export_theme(token, theme, spec, skip_existing=not force)
        copy_section_banners(theme)
        # Export section-banner nodes not covered by banner copies (e.g. theme-four #3)
        extra = spec.get("section_banner", [])
        copies = {d for _, d in SECTION_BANNER_COPIES.get(theme, [])}
        remaining = [nid for i, nid in enumerate(extra, 1) if i not in copies]
        if remaining:
            print(f"  section-banner extras: {len(remaining)}", flush=True)
            for idx, nid in zip(sorted(set(range(1, 4)) - copies), remaining):
                fname = f"{idx}.png"
                dest = UPLOADS / theme / "section-banner" / fname
                if dest.exists() and dest.stat().st_size > 1000:
                    continue
                urls = export_batch(token, [nid])
                u = urls.get(nid)
                if u:
                    download(u, dest)
                    print(f"    saved section-banner/{fname}", flush=True)
                time.sleep(2.0)

    print("\nAll Figma assets exported to public/uploads/", flush=True)


if __name__ == "__main__":
    main()
