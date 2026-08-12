"""Rebuild theme-three content from public/uploads/theme-three assets."""
import json
from pathlib import Path

BASE = Path(r"d:/laragon/www/sos_backend_3.0/public")
CONTENT = BASE / "theme-content" / "theme-three"
UPLOADS = BASE / "uploads" / "theme-three"
VENDOR = {"id": 1, "name": "SOS Saree House", "email": "hello@sossareehouse.com"}

CATS = {
    1: ("Trendy Sarees", "trendy-sarees"),
    2: ("Western Wedding Attire", "western-wedding-attire"),
    3: ("Women Blazer", "women-blazer"),
    4: ("Bridal Jewellery", "bridal-jewellery"),
}
SUBS = {
    # id: (category_id, name, slug)
    1: (1, "Silk Sarees", "silk-sarees"),
    2: (1, "Cotton Sarees", "cotton-sarees"),
    3: (1, "Festive Sarees", "festive-sarees"),
    4: (1, "Party Wear", "party-wear"),
    5: (2, "Bridal Gowns", "bridal-gowns"),
    6: (2, "Reception Gowns", "reception-gowns"),
    7: (3, "Blazers", "blazers"),
    8: (3, "Hijab Sets", "hijab-sets"),
    9: (3, "Modest Dresses", "modest-dresses"),
    10: (4, "Jewelry Sets", "jewelry-sets"),
    11: (4, "Clutches & Bags", "clutches-bags"),
}
BRANDS = {
    1: ("Rupkatha Weaves", "rupkatha-weaves"),
    2: ("Nandini Loom", "nandini-loom"),
    3: ("Shwetali Studio", "shwetali-studio"),
    4: ("Kanchi Heritage", "kanchi-heritage"),
    5: ("Monsoon Muse", "monsoon-muse"),
    6: ("Anika Ethnic", "anika-ethnic"),
    7: ("Noor Boutique", "noor-boutique"),
    8: ("Tasar Tale", "tasar-tale"),
}

# img, name, cat, sub, brand, selling, original, feature
# Sections of 8 aligned to Figma: Trendy Sarees | Western Wedding | Women Blazer
CATALOG = [
    # --- Our Trendy Sarees (1-8) ---
    (1, "Red Silk Saree with Gold & Blue Border", 1, 1, 1, 7490, 8990, 1),
    (2, "Bridal Cream Gold Embroidered Saree Set", 1, 3, 4, 12990, 15990, 1),
    (3, "Lemon Yellow Butterfly Border Cotton Saree", 1, 2, 2, 3290, 3990, 1),
    (4, "Emerald Velvet Bridal Lehenga Set", 1, 3, 4, 18990, 22990, 1),
    (5, "Deep Green Saree with Red Contrast Pallu", 1, 1, 1, 5990, 7490, 1),
    (6, "Ivory Temple Border Festive Saree", 1, 3, 4, 8990, 10990, 1),
    (7, "Classic Red & Blue Geometric Border Saree", 1, 1, 1, 6990, 8490, 1),
    (8, "Black Silver Embroidered Party Co-ord Set", 1, 4, 5, 4590, 5490, 1),
    # --- Western Wedding Attire (9-16) ---
    (9, "Ivory Lace Feather Neck Bridal Dress", 2, 5, 7, 24990, 29990, 1),
    (10, "White Floral Applique Off-Shoulder Gown", 2, 5, 7, 22990, 27990, 1),
    (11, "Classic White Lace Mermaid Wedding Gown", 2, 5, 3, 26990, 31990, 1),
    (12, "Puff Sleeve Embroidered Bridal Ball Gown", 2, 5, 3, 28990, 34990, 1),
    (13, "Scalloped Lace Bridal Gown with Bouquet", 2, 5, 7, 25990, 30990, 1),
    (14, "Ivory Off-Shoulder Lace Back Wedding Gown", 2, 6, 3, 23990, 28990, 1),
    (15, "Sheer Sleeve Plunging Bridal Evening Gown", 2, 6, 7, 21990, 26990, 1),
    (16, "Sparkle Ball Gown Wedding Dress with Tiara", 2, 5, 4, 31990, 37990, 1),
    # --- Women Blazer / Modest (17-24) ---
    (17, "Beige Belted Blazer with Tan Hijab", 3, 7, 6, 4990, 5990, 1),
    (18, "Light Wash Denim Shirt with Blue Hijab", 3, 8, 6, 2890, 3490, 1),
    (19, "Black Blazer & Rib Knit Modest Set", 3, 7, 8, 4590, 5490, 1),
    (20, "Baby Pink Smocked Dress with Lilac Hijab", 3, 9, 5, 3990, 4790, 1),
    (21, "Black Abaya Niqab Set with Crystal Headband", 3, 8, 8, 4290, 5190, 1),
    (22, "Hot Pink Blazer with Dusty Rose Hijab", 3, 7, 6, 4790, 5690, 1),
    (23, "Camel Sweater & Blush Pleated Skirt Set", 3, 9, 5, 5190, 6290, 1),
    (24, "Champagne Blazer with Soft Beige Hijab", 3, 7, 6, 4690, 5590, 1),
]


def slugify(name: str) -> str:
    s = name.lower()
    for ch in "&/'.":
        s = s.replace(ch, "")
    s = s.replace(" ", "-")
    while "--" in s:
        s = s.replace("--", "-")
    return s[:80]


def prices(selling, original):
    rate = round((original - selling) / original * 100, 2)
    return f"{selling:.2f}", f"{original:.2f}", f"{rate:.2f}"


def make(pid, row):
    img, name, cat, sub, brand, selling, original, feature = row
    sell, orig, rate = prices(float(selling), float(original))
    cat_name, _ = CATS[cat]
    sub_name = SUBS[sub][1]
    brand_name, brand_slug = BRANDS[brand]
    supplier = 1 if cat == 1 else (3 if cat == 2 else 4)
    warehouse = 1 if pid % 3 else 2
    gallery = []
    # pair nearby same-section image as gallery
    pair = img + 1 if img % 8 != 0 else img - 1
    if 1 <= pair <= 24:
        gallery = [pair]

    is_gown = cat == 2
    variants = (
        [
            {"id": 1, "name": "Size", "options": ["S", "M", "L", "XL"]},
            {"id": 2, "name": "Color", "options": ["Ivory", "White"]},
        ]
        if is_gown
        else [
            {"id": 1, "name": "Size", "options": ["Free Size", "S", "M", "L"]},
            {"id": 2, "name": "Blouse Piece" if cat == 1 else "Color", "options": ["Included"] if cat == 1 else ["Standard"]},
        ]
    )
    tags = ", ".join([w for w in name.replace("-", " ").split() if len(w) > 2][:6]).lower()
    return {
        "id": pid,
        "category_id": cat,
        "subcategory_id": sub,
        "brand_id": brand,
        "user_id": 1,
        "slug": slugify(name),
        "name": name,
        "short_description": f"Premium {cat_name.lower()} from SOS Saree House.",
        "long_description": f"<p>{name}. Designs that express your stories — elegant fabrics and refined finishing.</p>",
        "selling_price": sell,
        "original_price": orig,
        "image": f"uploads/theme-three/product/{img}.png",
        "status": "active",
        "meta_title": name,
        "meta_keyword": tags,
        "meta_description": f"Shop {name} at SOS Saree House.",
        "tags": tags,
        "commision_type": None,
        "request": None,
        "user_type": None,
        "discount_type": "percentage",
        "discount_rate": rate,
        "rejected_details": None,
        "created_at": "2026-04-10T17:00:00.000000Z",
        "updated_at": "2026-04-10T17:00:00.000000Z",
        "deleted_at": None,
        "variants": variants,
        "selling_type": "retail",
        "selling_details": None,
        "advance_payment": None,
        "single_advance_payment_type": None,
        "is_connect_bulk_single": "1",
        "specifications": [
            {"specification": "Category", "specification_ans": cat_name},
            {"specification": "Type", "specification_ans": sub_name},
            {"specification": "Brand", "specification_ans": brand_name},
        ],
        "uniqid": f"sar-prod-{pid:03d}",
        "sku": f"SAR-{pid:03d}",
        "distributor_price": f"{round(float(sell) * 0.92, 2):.2f}",
        "alert_qty": 4,
        "supplier_id": supplier,
        "warehouse_id": warehouse,
        "exp_date": None,
        "barcode": f"SAR{pid:04d}",
        "warranty": "No Warranty",
        "is_feature": feature,
        "is_affiliate": 0,
        "discount_price": None,
        "vendor_id": 1,
        "pre_order": "0",
        "discount_percentage": rate,
        "product_type": "system",
        "wc_product_id": None,
        "market_place_brand_id": None,
        "market_place_category_id": None,
        "market_place_subcategory_id": None,
        "productrating_avg_rating": "4.8",
        "vendor": VENDOR,
        "brand": {"id": brand, "user_id": 1, "name": brand_name, "slug": brand_slug},
        "category": {"id": cat, "name": cat_name},
        "subcategory": {"id": sub, "name": sub_name},
        "product_image": [
            {
                "id": i,
                "product_id": pid,
                "image": f"uploads/theme-three/product/{g}.png",
                "created_at": "2026-04-10T17:00:00.000000Z",
            }
            for i, g in enumerate(gallery, 1)
        ],
        "productrating": [],
        "supplier": {
            "id": supplier,
            "supplier_name": {1: "Dhaka Saree House", 3: "Bridal Couture Sourcing", 4: "Ethnic Accessories Hub"}[supplier],
            "business_name": {1: "Dhaka Saree House Ltd.", 3: "Bridal Couture Sourcing", 4: "Ethnic Accessories Hub"}[supplier],
        },
        "warehouse": {
            "id": warehouse,
            "name": {1: "Central Saree Warehouse", 2: "Banani Saree Boutique Stock", 3: "Chattogram Ethnic Hub"}[warehouse],
        },
        "product_variant": [],
    }


def main():
    missing = [i for i, *_ in CATALOG if not (UPLOADS / "product" / f"{i}.png").exists()]
    if missing:
        raise SystemExit(f"Missing product images: {missing}")

    products = [make(i, row) for i, row in enumerate(CATALOG, 1)]
    (CONTENT / "product.json").write_text(
        json.dumps(products, ensure_ascii=False, indent="\t") + "\n", encoding="utf-8"
    )

    stamp = "2026-04-10T16:00:00.000000Z"
    categories = []
    for cid, (name, slug) in CATS.items():
        categories.append(
            {
                "id": cid,
                "name": name,
                "slug": slug,
                "description": f"{name} collection curated for SOS Saree House.",
                "status": "active",
                "image": f"uploads/theme-three/category/{cid}.png",
                "created_at": stamp,
                "updated_at": stamp,
                "deleted_at": None,
            }
        )
    (CONTENT / "category.json").write_text(
        json.dumps(categories, ensure_ascii=False, indent="\t") + "\n", encoding="utf-8"
    )

    subcategories = []
    for sid, (cid, name, slug) in SUBS.items():
        subcategories.append(
            {
                "id": sid,
                "category_id": cid,
                "name": name,
                "slug": slug,
                "status": "active",
                "created_at": stamp,
                "updated_at": stamp,
                "deleted_at": None,
            }
        )
    (CONTENT / "sub-category.json").write_text(
        json.dumps(subcategories, ensure_ascii=False, indent="\t") + "\n", encoding="utf-8"
    )

    cms = [
        {
            "app_name": "SOS Saree House",
            "home_page_title": "Designs That Express Your Stories",
            "banner_title": "Designs That Express Your Stories",
            "banner_description": "We provide the largest clothing collection for any season. Choose trendy or classy designs according to your preferences.",
            "color_primary": "#8B2942",
            "theme": "three",
            "logo": "uploads/theme-three/logo/logo.png",
            "seo_meta_title": "SOS Saree House - Trendy Sarees & Wedding Wear",
            "seo_meta_description": "Shop trendy sarees, western wedding attire, and modest blazer collections.",
            "seo_meta_keywords": "saree, wedding gown, bridal, blazer, modest fashion, SOS Saree House",
            "seo_meta_image": "uploads/theme-three/banner/1.png",
            "banner_1": "uploads/theme-three/banner/1.png",
            "banner_1_url": "#",
            "banner_2": "uploads/theme-three/gallery/1.png",
            "banner_2_url": "#",
            "banner_3": "uploads/theme-three/gallery/2.png",
            "banner_3_url": "#",
            "populer_section_banner": "uploads/theme-three/section-banner/1.png",
            "populer_section_title": "Our Trendy Sarees",
            "three_column_banner_1": "uploads/theme-three/gallery/1.png",
            "three_column_banner_1_url": "#",
            "three_column_banner_2": "uploads/theme-three/gallery/2.png",
            "three_column_banner_2_url": "#",
            "three_column_banner_3": "uploads/theme-three/gallery/3.png",
            "three_column_banner_3_url": "#",
            "two_column_banner_1": "uploads/theme-three/gallery/4.png",
            "two_column_banner_1_url": "#",
            "two_column_banner_2": "uploads/theme-three/section-banner/1.png",
            "two_column_banner_2_url": "#",
            "recomended_category_id_1": "2",
            "recomended_sub_category_id_1": "5",
            "recomended_category_id_2": "2",
            "recomended_sub_category_id_2": "6",
            "recomended_category_id_3": "3",
            "recomended_sub_category_id_3": "7",
            "recomended_category_id_4": "3",
            "recomended_sub_category_id_4": "8",
            "best_setting_title": "Western Wedding Attire",
            "best_setting_category_id_1": "2",
            "best_setting_sub_category_id_1": "5",
            "best_setting_category_id_2": "2",
            "best_setting_sub_category_id_2": "5",
            "best_setting_category_id_3": "2",
            "best_setting_sub_category_id_3": "6",
            "best_setting_category_id_4": "2",
            "best_setting_sub_category_id_4": "6",
            "best_category_id": "3",
            "best_sub_category_id": "7",
            "best_section_title": "Women Blazer",
            "populer_section_category_id_1": "1",
            "populer_section_subcategory_id_1": "1",
            "populer_section_category_id_2": "1",
            "populer_section_subcategory_id_2": "2",
            "populer_section_category_id_3": "1",
            "populer_section_subcategory_id_3": "3",
            "populer_section_category_id_4": "1",
            "populer_section_subcategory_id_4": "4",
            "footer_logo": "uploads/theme-three/logo/logo.png",
            "footer_description": "Premium sarees, western wedding attire, and modest fashion curated for every celebration.",
            "footer_contact_number_one": "+880 9612 456789",
            "footer_contact_address_one": "Mirpur, Dhaka, Bangladesh",
            "footer_contact_number_two": "+880 9612 456790",
            "footer_contact_address_two": "Banani, Dhaka, Bangladesh",
            "footer_copyright_text": "© 2026 SOS Saree House. All rights reserved.",
            "footer_payment_methods": "uploads/theme-three/others/payment-methods.png",
        }
    ]
    (CONTENT / "cms.json").write_text(json.dumps(cms, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")

    banners = [
        {
            "id": 1,
            "title": "Designs That Express Your Stories",
            "subtitle": "Trendy Saree Collection",
            "image": "uploads/theme-three/banner/1.png",
            "link": "#",
            "order": 1,
            "status": "active",
        },
        {
            "id": 2,
            "title": "Wedding Collection",
            "subtitle": "Draped in Emotions, Adorned in Elegance",
            "image": "uploads/theme-three/gallery/1.png",
            "link": "#",
            "order": 2,
            "status": "active",
        },
        {
            "id": 3,
            "title": "Our Trendy Sarees",
            "subtitle": "New Season Edit",
            "image": "uploads/theme-three/gallery/2.png",
            "link": "#",
            "order": 3,
            "status": "active",
        },
        {
            "id": 4,
            "title": "Western Wedding Attire",
            "subtitle": "Bridal Collection",
            "image": "uploads/theme-three/gallery/3.png",
            "link": "#",
            "order": 4,
            "status": "active",
        },
    ]
    (CONTENT / "banner.json").write_text(json.dumps(banners, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")

    offers = [
        {"id": 1, "title": "Best Offer of the Week"},
        {"id": 2, "title": "New Arrivals"},
        {"id": 3, "title": "Top Rated Products"},
        {"id": 4, "title": "Bridal Sale"},
        {"id": 5, "title": "Festive Collection"},
    ]
    (CONTENT / "offer.json").write_text(json.dumps(offers, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")

    services = [
        {
            "id": 1,
            "title": "High quality",
            "description": "Handpicked fabrics and elegant finishing",
            "icon": "uploads/theme-three/service/1.png",
            "order": 1,
            "status": "active",
        },
        {
            "id": 2,
            "title": "24/7 Support",
            "description": "Guidance for wedding and festive looks",
            "icon": "uploads/theme-three/service/2.png",
            "order": 2,
            "status": "active",
        },
        {
            "id": 3,
            "title": "Secure Payment",
            "description": "Safe checkout with multiple payment options",
            "icon": "uploads/theme-three/service/3.png",
            "order": 3,
            "status": "active",
        },
        {
            "id": 4,
            "title": "30 Days Return",
            "description": "Easy returns on eligible orders",
            "icon": "uploads/theme-three/service/4.png",
            "order": 4,
            "status": "active",
        },
    ]
    (CONTENT / "services.json").write_text(json.dumps(services, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")

    from collections import Counter

    print("wrote", len(products), "products")
    print(dict(Counter(p["category_id"] for p in products)))
    for p in products:
        print(f"{p['id']:2} cat={p['category_id']} {p['image']} | {p['name']}")


if __name__ == "__main__":
    main()
