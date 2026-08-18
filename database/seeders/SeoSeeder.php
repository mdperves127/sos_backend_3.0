<?php

namespace Database\Seeders;

use App\Models\Seo;
use Illuminate\Database\Seeder;

class SeoSeeder extends Seeder
{
    public function run()
    {
        $pages = [
            [
                'page_url'  => '/',
                'seo_title' => 'SOSComrz - Grow Your Online Business',
                'seo_value' => 'Launch and manage your online store with SOSComrz. Packages, services, and tools for vendors and affiliates.',
            ],
            [
                'page_url'  => '/about',
                'seo_title' => 'About Us - SOSComrz',
                'seo_value' => 'Learn about SOSComrz, our mission, and how we help businesses sell online.',
            ],
            [
                'page_url'  => '/contact',
                'seo_title' => 'Contact Us - SOSComrz',
                'seo_value' => 'Get in touch with the SOSComrz team for support, partnership, and product questions.',
            ],
            [
                'page_url'  => '/faq',
                'seo_title' => 'FAQ - SOSComrz',
                'seo_value' => 'Answers to frequently asked questions about SOSComrz packages, vendors, and affiliates.',
            ],
            [
                'page_url'  => '/pricing',
                'seo_title' => 'Pricing & Packages - SOSComrz',
                'seo_value' => 'Compare SOSComrz subscription packages for vendors and affiliates.',
            ],
            [
                'page_url'  => '/services',
                'seo_title' => 'Services - SOSComrz',
                'seo_value' => 'Explore SOSComrz services for ecommerce, marketing, and business growth.',
            ],
            [
                'page_url'  => '/login',
                'seo_title' => 'Login - SOSComrz',
                'seo_value' => 'Sign in to your SOSComrz account.',
            ],
            [
                'page_url'  => '/register',
                'seo_title' => 'Register - SOSComrz',
                'seo_value' => 'Create a SOSComrz account and start selling online.',
            ],
        ];

        foreach ( $pages as $page ) {
            Seo::updateOrCreate(
                [ 'page_url' => $page['page_url'] ],
                [
                    'seo_title' => $page['seo_title'],
                    'seo_value' => $page['seo_value'],
                ]
            );
        }
    }
}
