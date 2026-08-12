import json
from pathlib import Path
from collections import Counter

base = Path(r"d:/laragon/www/sos_backend_3.0/public")
p = json.loads((base / "theme-content/theme-three/product.json").read_text(encoding="utf-8"))
print("products in json", len(p))
print("cats", dict(Counter(x.get("category_id") for x in p)))
imgs = sorted({str(x["image"]).replace("\\", "/") for x in p})
print("unique images", len(imgs))
missing = [i for i in imgs if not (base / i).exists()]
print("missing", len(missing))
if missing:
    print(missing[:10])
for x in p[:20]:
    print(x["id"], x["name"], x["image"], "cat", x["category_id"], "sub", x.get("subcategory_id"))

homes = base / "figma_homes.json"
if homes.exists():
    data = json.loads(homes.read_text(encoding="utf-8"))
    node = data.get("nodes", {}).get("381:3580")
    if node:
        texts = []

        def walk(n):
            if n.get("type") == "TEXT":
                t = n.get("characters", "").strip()
                if t:
                    texts.append(t)
            for c in n.get("children") or []:
                walk(c)

        walk(node["document"])
        (base / "_t3_texts.txt").write_text("\n".join(texts), encoding="utf-8")
        print("figma texts", len(texts))
