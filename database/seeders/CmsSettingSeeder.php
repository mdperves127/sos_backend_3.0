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
            'logo' => 'uploads/theme-one/logo/logo.png',
            'theme' => 'one',

            // SEO Settings
            'seo_meta_title' => 'My Store - Best Online Shopping',
            'seo_meta_description' => 'Shop the best products online at My Store. Quality products at affordable prices.',
            'seo_meta_keywords' => 'online shopping, ecommerce, store, products',
            'seo_meta_image' => 'uploads/theme-one/seo/seo-image.png',

            // Scripts
            'scripts_google_analytics' => '',
            'scripts_google_adsense' => '',
            'scripts_google_recaptcha' => '',
            'scripts_facebook_pixel' => '',
            'scripts_facebook_messenger' => '',
            'scripts_whatsapp_chat' => '',
            'scripts_google_tag_manager' => '',

            // Footer Settings
            'footer_logo' => 'uploads/theme-one/others/footer-logo.png',
            'footer_description' => 'Your trusted online shopping destination. We offer quality products with excellent customer service.',
            'footer_contact_number_one' => '+1 234 567 8900',
            'footer_contact_address_one' => '123 Main Street, City, State 12345',
            'footer_contact_number_two' => '+1 234 567 8901',
            'footer_contact_address_two' => '456 Second Street, City, State 12345',
            'footer_copyright_text' => '© ' . date('Y') . ' My Store. All rights reserved.',
            'footer_payment_methods' => 'uploads/theme-one/others/payment-methods.png',

            // Banner Settings
            'banner_1' => 'uploads/theme-one/banner/1.png',
            'banner_1_url' => '#',
            'banner_2' => 'uploads/theme-one/banner/2.png',
            'banner_2_url' => '#',
            'banner_3' => 'uploads/theme-one/banner/3.png',
            'banner_3_url' => '#',

            // Three Column Banners
            'three_column_banner_1' => 'uploads/theme-one/three-column-banner-1/three-column-banner-1.png',
            'three_column_banner_1_url' => '#',
            'three_column_banner_2' => 'uploads/theme-one/three-column-banner-2/three-column-banner-2.png',
            'three_column_banner_2_url' => '#',
            'three_column_banner_3' => 'uploads/theme-one/three-column-banner-3/three-column-banner-3.png',
            'three_column_banner_3_url' => '#',

            // Two Column Banners
            'two_column_banner_1' => 'uploads/theme-one/two-column-banner-1/two-column-banner-1.png',
            'two_column_banner_1_url' => '#',
            'two_column_banner_2' => 'uploads/theme-one/two-column-banner-2/two-column-banner-2.png',
            'two_column_banner_2_url' => '#',

            // Recommended Categories
            'recomended_category_id_1' => 1,
            'recomended_sub_category_id_1' => 1,
            'recomended_category_id_2' => 2,
            'recomended_sub_category_id_2' => 2,
            'recomended_category_id_3' => 3,
            'recomended_sub_category_id_3' => 3,
            'recomended_category_id_4' => 4,
            'recomended_sub_category_id_4' => 4,

            // Best Settings
            'best_setting_title' => 'Best Products',
            'best_setting_category_id_1' => 1,
            'best_setting_sub_category_id_1' => 1,
            'best_setting_category_id_2' => 2,
            'best_setting_sub_category_id_2' => 2,
            'best_setting_category_id_3' => 3,
            'best_setting_sub_category_id_3' => 3,
            'best_setting_category_id_4' => 4,
            'best_setting_sub_category_id_4' => 4,
            'best_category_id' => 1,
            'best_sub_category_id' => 1,
            'best_section_title' => 'Best Sellers',

            // Popular Section
            'populer_section_title' => 'Popular Products',
            'populer_section_banner' => 'uploads/theme-one/populer-section-banner/populer-section-banner.png',
            'populer_section_category_id_1' => 1,
            'populer_section_subcategory_id_1' => 1,
            'populer_section_category_id_2' => 2,
            'populer_section_subcategory_id_2' => 2,
            'populer_section_category_id_3' => 3,
            'populer_section_subcategory_id_3' => 3,
            'populer_section_category_id_4' => 4,
            'populer_section_subcategory_id_4' => 4,

            // Social URLs
            'fb_url' => 'https://www.facebook.com/',
            'x_url' => 'https://twitter.com/',
            'instagram_url' => 'https://www.instagram.com/',
            'youtube_url' => 'https://www.youtube.com/',
            'tiktok_url' => 'https://www.tiktok.com/',
            'telegram_url' => 'https://t.me/',
            'whatsapp_url' => 'https://wa.me/',
        ]);



        $this->command->info('CMS Settings seeded successfully!');
    }
}
