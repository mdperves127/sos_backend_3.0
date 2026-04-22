<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class TenantSetupSeeder extends Seeder
{
    /**
     * Seed the tenant database with baseline data.
     */
    public function run(): void
    {
        $this->call(CmsSettingSeeder::class);

        $tenant = function_exists('tenant') ? tenant() : null;

        if (($tenant?->type ?? null) === 'dropshipper') {
            $this->command?->info('Skipping default product seed for dropshipper tenant.');
            return;
        }

        $ownerId = User::query()->value('id') ?? 1;

        $category = Category::query()->where('slug', 'sample-products')->first() ?? new Category();
        $category->name = 'Sample Products';
        $category->slug = 'sample-products';
        $category->description = 'Starter category for newly created merchant tenants.';
        $category->image = 'uploads/theme-one/category/1.png';
        $category->status = 'active';
        $category->save();

        $brand = Brand::query()->updateOrCreate(
            ['slug' => 'starter-brand'],
            [
                'user_id' => $ownerId,
                'name' => 'Starter Brand',
                'description' => 'Default brand for seeded tenant products.',
                'meta_title' => 'Starter Brand',
                'meta_keyword' => 'starter,default,brand',
                'meta_description' => 'Default brand for tenant sample products.',
                'image' => 'uploads/theme-one/brand/1.png',
                'status' => 'active',
            ]
        );

        $supplier = Supplier::query()->updateOrCreate(
            ['supplier_slug' => 'starter-supplier'],
            [
                'user_id' => $ownerId,
                'vendor_id' => $ownerId,
                'supplier_name' => 'Starter Supplier',
                'supplier_id' => 'SUP-STARTER-001',
                'business_name' => 'Starter Supplier',
                'phone' => null,
                'email' => null,
                'address' => 'Default supplier for tenant seed data.',
                'description' => 'Used by the sample products seeded for new merchant tenants.',
                'status' => 'active',
            ]
        );

        $warehouse = Warehouse::query()->updateOrCreate(
            ['slug' => 'starter-warehouse'],
            [
                'user_id' => $ownerId,
                'vendor_id' => $ownerId,
                'name' => 'Starter Warehouse',
                'description' => 'Default warehouse for tenant seed data.',
                'status' => 'active',
            ]
        );

        $products = [
            [
                'sku' => 'SAMPLE-001',
                'slug' => 'sample-cotton-t-shirt',
                'uniqid' => 'sample-product-001',
                'name' => 'Sample Cotton T-Shirt',
                'short_description' => 'Soft cotton t-shirt for everyday wear.',
                'long_description' => 'A lightweight sample product seeded automatically for new merchant tenants. Suitable for testing storefront, listing, and order flows.',
                'image' => 'uploads/theme-one/products/1.png',
                'selling_price' => 499,
                'original_price' => 650,
                'qty' => 30,
                'barcode' => 'SAMPLE000001',
                'tags' => ['sample', 't-shirt', 'cotton'],
                'meta_keyword' => ['sample', 'cotton t-shirt', 'starter product'],
                'specifications' => [
                    ['specification' => 'Material', 'specification_ans' => '100% Cotton'],
                    ['specification' => 'Fit', 'specification_ans' => 'Regular'],
                ],
            ],
            [
                'sku' => 'SAMPLE-002',
                'slug' => 'sample-everyday-backpack',
                'uniqid' => 'sample-product-002',
                'name' => 'Sample Everyday Backpack',
                'short_description' => 'Compact backpack for daily essentials.',
                'long_description' => 'A sample backpack product intended to populate a fresh tenant storefront with realistic-looking starter inventory.',
                'image' => 'uploads/theme-one/products/2.png',
                'selling_price' => 1290,
                'original_price' => 1550,
                'qty' => 18,
                'barcode' => 'SAMPLE000002',
                'tags' => ['sample', 'backpack', 'travel'],
                'meta_keyword' => ['sample', 'backpack', 'starter product'],
                'specifications' => [
                    ['specification' => 'Capacity', 'specification_ans' => '22 Liters'],
                    ['specification' => 'Material', 'specification_ans' => 'Polyester'],
                ],
            ],
            [
                'sku' => 'SAMPLE-003',
                'slug' => 'sample-water-bottle',
                'uniqid' => 'sample-product-003',
                'name' => 'Sample Stainless Water Bottle',
                'short_description' => 'Reusable insulated bottle with secure cap.',
                'long_description' => 'This seeded bottle product gives new merchant tenants another category of inventory for product detail and filtering tests.',
                'image' => 'uploads/theme-one/products/3.png',
                'selling_price' => 790,
                'original_price' => 950,
                'qty' => 24,
                'barcode' => 'SAMPLE000003',
                'tags' => ['sample', 'bottle', 'lifestyle'],
                'meta_keyword' => ['sample', 'water bottle', 'starter product'],
                'specifications' => [
                    ['specification' => 'Capacity', 'specification_ans' => '750ml'],
                    ['specification' => 'Material', 'specification_ans' => 'Stainless Steel'],
                ],
            ],
            [
                'sku' => 'SAMPLE-004',
                'slug' => 'sample-wireless-mouse',
                'uniqid' => 'sample-product-004',
                'name' => 'Sample Wireless Mouse',
                'short_description' => 'Reliable wireless mouse for office use.',
                'long_description' => 'A seeded electronics-style product that helps verify pricing, stock, and storefront rendering in a new tenant database.',
                'image' => 'uploads/theme-one/products/4.png',
                'selling_price' => 1150,
                'original_price' => 1380,
                'qty' => 20,
                'barcode' => 'SAMPLE000004',
                'tags' => ['sample', 'mouse', 'electronics'],
                'meta_keyword' => ['sample', 'wireless mouse', 'starter product'],
                'specifications' => [
                    ['specification' => 'Connectivity', 'specification_ans' => '2.4GHz Wireless'],
                    ['specification' => 'Battery', 'specification_ans' => 'AA Battery'],
                ],
            ],
            [
                'sku' => 'SAMPLE-005',
                'slug' => 'sample-ceramic-mug',
                'uniqid' => 'sample-product-005',
                'name' => 'Sample Ceramic Mug',
                'short_description' => 'Ceramic mug for coffee or tea.',
                'long_description' => 'A simple seeded home item that rounds out the initial default catalog for merchant tenants.',
                'image' => 'uploads/theme-one/products/5.png',
                'selling_price' => 350,
                'original_price' => 450,
                'qty' => 40,
                'barcode' => 'SAMPLE000005',
                'tags' => ['sample', 'mug', 'home'],
                'meta_keyword' => ['sample', 'ceramic mug', 'starter product'],
                'specifications' => [
                    ['specification' => 'Capacity', 'specification_ans' => '330ml'],
                    ['specification' => 'Finish', 'specification_ans' => 'Glossy'],
                ],
            ],
        ];

        foreach ($products as $index => $productData) {
            Product::query()->updateOrCreate(
                ['sku' => $productData['sku']],
                [
                    'category_id' => $category->id,
                    'subcategory_id' => null,
                    'brand_id' => $brand->id,
                    'user_id' => $ownerId,
                    'vendor_id' => $ownerId,
                    'supplier_id' => $supplier->id,
                    'warehouse_id' => $warehouse->id,
                    'slug' => $productData['slug'],
                    'name' => $productData['name'],
                    'short_description' => $productData['short_description'],
                    'long_description' => $productData['long_description'],
                    'selling_price' => $productData['selling_price'],
                    'original_price' => $productData['original_price'],
                    'distributor_price' => $productData['original_price'],
                    'qty' => $productData['qty'],
                    'image' => $productData['image'],
                    'status' => 'active',
                    'meta_title' => $productData['name'],
                    'meta_keyword' => $productData['meta_keyword'],
                    'meta_description' => $productData['short_description'],
                    'tags' => $productData['tags'],
                    'discount_type' => null,
                    'discount_rate' => 0,
                    'variants' => [],
                    'selling_type' => 'single',
                    'selling_details' => null,
                    'advance_payment' => 0,
                    'single_advance_payment_type' => null,
                    'is_connect_bulk_single' => 0,
                    'specifications' => $productData['specifications'],
                    'uniqid' => $productData['uniqid'],
                    'barcode' => $productData['barcode'],
                    'warranty' => 'No warranty',
                    'is_feature' => $index < 2 ? 1 : 0,
                    'is_affiliate' => 0,
                    'pre_order' => '0',
                    'product_type' => 'system',
                ]
            );
        }

        $this->command?->info('Default merchant products seeded successfully.');
    }
}
