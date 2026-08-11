import copy
import json
import shutil
from pathlib import Path

BASE = Path("d:/laragon/www/sos_backend_3.0/public")
THEME = BASE / "theme-content/theme-two"
UPLOADS = BASE / "uploads/theme-two"
PRODUCT_JSON = THEME / "product.json"
SUFFIX = " Special Edition"

products = json.load(open(PRODUCT_JSON, encoding="utf-8"))
source_count = len(products)
copies_per_item = 2  # original + 2 duplicates = 3x catalog
suffixes = [" Special Edition", " Limited Offer", " Premium Pack"]

expanded = list(products)
next_id = source_count + 1

for round_idx in range(copies_per_item):
    suffix = suffixes[round_idx % len(suffixes)]
    slug_suffix = suffix.lower().replace(" ", "-").strip("-")

    for index, item in enumerate(products, start=1):
        dup = copy.deepcopy(item)
        new_id = next_id
        next_id += 1

        src_image = UPLOADS / "product" / f"{index}.png"
        dst_image = UPLOADS / "product" / f"{new_id}.png"
        if src_image.exists():
            shutil.copy2(src_image, dst_image)

        sell = round(float(item["selling_price"]) * (0.97 - round_idx * 0.02), 2)
        orig = float(item["original_price"])
        discount_rate = f"{round((orig - sell) / orig * 100, 2):.2f}" if orig > sell else "0.00"

        dup.update({
            "id": new_id,
            "slug": f"{item['slug']}-{slug_suffix}",
            "name": f"{item['name']}{suffix}",
            "short_description": f"{item['short_description']} Available while stocks last.",
            "long_description": item["long_description"].replace("</p>", f"{suffix}.</p>"),
            "selling_price": f"{sell:.2f}",
            "original_price": f"{orig:.2f}",
            "discount_rate": discount_rate,
            "discount_percentage": discount_rate,
            "discount_price": None,
            "image": f"uploads/theme-two/product/{new_id}.png",
            "uniqid": f"sos-prod-{new_id:03d}",
            "sku": f"SOS-{new_id:03d}",
            "barcode": f"SOS{new_id:04d}",
            "distributor_price": f"{sell * 0.92:.2f}",
            "is_feature": 1 if (new_id + round_idx) % 5 == 0 else 0,
            "productrating_avg_rating": item.get("productrating_avg_rating", "4.5"),
        })

        dup["product_image"] = [{
            "id": new_id,
            "product_id": new_id,
            "image": f"uploads/theme-two/product/{new_id}.png",
            "created_at": item.get("created_at", "2026-04-10T15:00:00.000000Z"),
        }]

        expanded.append(dup)

with open(PRODUCT_JSON, "w", encoding="utf-8") as f:
    json.dump(expanded, f, indent="\t", ensure_ascii=False)
    f.write("\n")

print(f"Products: {source_count} -> {len(expanded)}")

# Service trust badges
service_dir = UPLOADS / "service"
service_dir.mkdir(parents=True, exist_ok=True)
brand_sources = list((UPLOADS / "brand").glob("*.png"))[:4]
for i, src in enumerate(brand_sources, start=1):
    shutil.copy2(src, service_dir / f"{i}.png")

services = [
    {
        "id": 1,
        "title": "High-quality Goods",
        "description": "Enjoy premium tech products at competitive prices",
        "icon": "uploads/theme-two/service/1.png",
        "order": 1,
        "status": "active",
    },
    {
        "id": 2,
        "title": "24/7 Live Chat",
        "description": "Get instant assistance from our support team",
        "icon": "uploads/theme-two/service/2.png",
        "order": 2,
        "status": "active",
    },
    {
        "id": 3,
        "title": "Express Shipping",
        "description": "Fast and reliable delivery across Bangladesh",
        "icon": "uploads/theme-two/service/3.png",
        "order": 3,
        "status": "active",
    },
    {
        "id": 4,
        "title": "Secure Payment",
        "description": "Multiple safe payment methods supported",
        "icon": "uploads/theme-two/service/4.png",
        "order": 4,
        "status": "active",
    },
]
with open(THEME / "services.json", "w", encoding="utf-8") as f:
    json.dump(services, f, indent=4, ensure_ascii=False)
    f.write("\n")

banners = [
    {"id": 1, "title": "Save Up To 20% Off", "subtitle": "17 Pro Max", "image": "uploads/theme-two/banner/1.png", "link": "#", "order": 1, "status": "active"},
    {"id": 2, "title": "Music Collection", "subtitle": "Bluetooth Speaker", "image": "uploads/theme-two/banner/2.png", "link": "#", "order": 2, "status": "active"},
    {"id": 3, "title": "Music Collection", "subtitle": "Bluetooth Headphone", "image": "uploads/theme-two/banner/3.png", "link": "#", "order": 3, "status": "active"},
    {"id": 4, "title": "Fresh & Limited Design", "subtitle": "Headphone", "image": "uploads/theme-two/banner/4.png", "link": "#", "order": 4, "status": "active"},
    {"id": 5, "title": "Save up to 40% off", "subtitle": "Smart TV 4K", "image": "uploads/theme-two/banner/5.png", "link": "#", "order": 5, "status": "active"},
    {"id": 6, "title": "Save up to 20% off", "subtitle": "iPhone 15", "image": "uploads/theme-two/banner/6.png", "link": "#", "order": 6, "status": "active"},
    {"id": 7, "title": "Save up to 30% off", "subtitle": "Digital Camera", "image": "uploads/theme-two/banner/7.png", "link": "#", "order": 7, "status": "active"},
    {"id": 8, "title": "Gadgets and Accessories", "subtitle": "Best Price", "image": "uploads/theme-two/banner/8.png", "link": "#", "order": 8, "status": "active"},
    {"id": 9, "title": "UP to 80% OFF", "subtitle": "iPhone 16 Pro", "image": "uploads/theme-two/banner/9.png", "link": "#", "order": 9, "status": "active"},
]
with open(THEME / "banner.json", "w", encoding="utf-8") as f:
    json.dump(banners, f, indent=4, ensure_ascii=False)
    f.write("\n")

offers = [
    {"id": 1, "title": "Best Offer of the Week"},
    {"id": 2, "title": "New Arrivals"},
    {"id": 3, "title": "Top Rated Products"},
    {"id": 4, "title": "Flash Sale Today"},
    {"id": 5, "title": "Free Delivery Offer"},
]
with open(THEME / "offer.json", "w", encoding="utf-8") as f:
    json.dump(offers, f, indent=4, ensure_ascii=False)
    f.write("\n")

print("Updated services.json, banner.json, offer.json")
