<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CmsSetting;

class CmsSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Check if CMS settings already exist
        if (CmsSetting::count() > 0) {
            $this->command->info('CMS Settings already exist. Skipping seeder.');
            return;
        }

        CmsSetting::create([
            // Basic Settings
            'app_name' => 'My Store',
            'home_page_title' => 'Welcome to Our Store',
            'color_primary' => '#007bff',
            'logo' => 'uploads/logo/default-logo.png',
            'theme' => 'one',

            // SEO Settings
            'seo_meta_title' => 'My Store - Best Online Shopping',
            'seo_meta_description' => 'Shop the best products online at My Store. Quality products at affordable prices.',
            'seo_meta_keywords' => 'online shopping, ecommerce, store, products',
            'seo_meta_image' => 'uploads/seo/default-seo-image.png',

            // Scripts
            'scripts_google_analytics' => '',
            'scripts_google_adsense' => '',
            'scripts_google_recaptcha' => '',
            'scripts_facebook_pixel' => '',
            'scripts_facebook_messenger' => '',
            'scripts_whatsapp_chat' => '',
            'scripts_google_tag_manager' => '',

            // Footer Settings
            'footer_logo' => 'uploads/footer-logo/default-footer-logo.png',
            'footer_description' => 'Your trusted online shopping destination. We offer quality products with excellent customer service.',
            'footer_contact_number_one' => '+1 234 567 8900',
            'footer_contact_address_one' => '123 Main Street, City, State 12345',
            'footer_contact_number_two' => '+1 234 567 8901',
            'footer_contact_address_two' => '456 Second Street, City, State 12345',
            'footer_copyright_text' => '© ' . date('Y') . ' My Store. All rights reserved.',
            'footer_payment_methods' => 'uploads/payment-methods/default-payment-methods.png',

            // Banner Settings
            'banner_1' => 'uploads/banner-1/default-banner-1.png',
            'banner_1_url' => '#',
            'banner_2' => 'uploads/banner-2/default-banner-2.png',
            'banner_2_url' => '#',
            'banner_3' => 'uploads/banner-3/default-banner-3.png',
            'banner_3_url' => '#',

            // Three Column Banners
            'three_column_banner_1' => 'uploads/three-column-banner-1/default-banner.png',
            'three_column_banner_1_url' => '#',
            'three_column_banner_2' => 'uploads/three-column-banner-2/default-banner.png',
            'three_column_banner_2_url' => '#',
            'three_column_banner_3' => 'uploads/three-column-banner-3/default-banner.png',
            'three_column_banner_3_url' => '#',

            // Two Column Banners
            'two_column_banner_1' => 'uploads/two-column-banner-1/default-banner.png',
            'two_column_banner_1_url' => '#',
            'two_column_banner_2' => 'uploads/two-column-banner-2/default-banner.png',
            'two_column_banner_2_url' => '#',

            // Recommended Categories
            'recomended_category_id_1' => '',
            'recomended_sub_category_id_1' => '',
            'recomended_category_id_2' => '',
            'recomended_sub_category_id_2' => '',
            'recomended_category_id_3' => '',
            'recomended_sub_category_id_3' => '',
            'recomended_category_id_4' => '',
            'recomended_sub_category_id_4' => '',

            // Best Settings
            'best_setting_title' => 'Best Products',
            'best_setting_category_id_1' => '',
            'best_setting_sub_category_id_1' => '',
            'best_setting_category_id_2' => '',
            'best_setting_sub_category_id_2' => '',
            'best_setting_category_id_3' => '',
            'best_setting_sub_category_id_3' => '',
            'best_setting_category_id_4' => '',
            'best_setting_sub_category_id_4' => '',
            'best_category_id' => '',
            'best_sub_category_id' => '',
            'best_section_title' => 'Best Sellers',

            // Popular Section
            'populer_section_title' => 'Popular Products',
            'populer_section_banner' => 'uploads/populer-section-banner/default-banner.png',
            'populer_section_category_id_1' => '',
            'populer_section_subcategory_id_1' => '',
            'populer_section_category_id_2' => '',
            'populer_section_subcategory_id_2' => '',
            'populer_section_category_id_3' => '',
            'populer_section_subcategory_id_3' => '',
            'populer_section_category_id_4' => '',
            'populer_section_subcategory_id_4' => '',
        ]);

        $this->command->info('CMS Settings seeded successfully!');
    }
}
