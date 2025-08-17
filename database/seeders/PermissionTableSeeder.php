<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $permissions = [
            'dashboard',
            'alluser',

            // vendor
            'add-vendor',
            'all-vendor',
            'active-vendor',
            'pending-vendor',

            // affiliate
            'add-affiliate',
            'all-affiliate',
            'active-affiliate',
            'pending-affiliate',

            // user
            'add-user',
            'all-user',
            'active-user',
            'pending-user',

            // vendor products
            'all-products',
            'active-products',
            'pending-products',
            'edit-products',
            'rejected-product',

            // affiliate request
            'all-request',
            'active-request',
            'pending-request',
            'rejected-request',

            // manage category
            'category',
            'sub-category',

            //Brand
            'brand',

            // manage order
            'all-order',
            'order-hold',
            'order-pending',
            'order-received',
            'delivery-processing',
            'product-delivered',
            'order-cancel',

            // withdraw
            'withdraw',

            // Home content
            'home-service',
            'organization',
            'it-service',
            'organization-two',
            'partner',
            'content',
            'home-update-content',

            // About content
            'companions',
            'mission',
            'testimonial',
            'members',
            'abount-update-content',

            //  General Content
            'general-update-content',

            // Advertise Content
            'faq',
            'advertise-update-content',

            // Service content
            'service-update-content',

            // Setting
            'setting',

            // Service topic
            'service-category',
            'service-sub-category',

            // support
            'support',

            //support topic
            'support-category',
            'support-problem-topic',

            // Advertiser
            'advertise-utility',
            'all-advertiser',

            // Subscription
            'subscription',

            // Users Response
            'users-response',

            // Coupon
            'create-coupon',
            'active-coupon',
            'request-coupon',
            'rejected-coupon',

            // Manag service
            'manage-service',

            // Service Order
            'service-order',
            'membership',

            //role
            'role'

        ];
        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
       }
    }
}
