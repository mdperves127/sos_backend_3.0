<?php

namespace Database\Seeders;

use App\Models\Subscription;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SubscriptionSedder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public static function run()
    {
        // vendor monthly
        $dataOne = [
            'subscription_user_type' => 'vendor',
            'subscription_package_type' => 'monthly',
            'card_symbol_icon' => 'fa fa-doller',
            'subscription_amount' => 50,
            'card_time' => 'monthy',
            'card_heading' => 'card_heading',
            'card_feature_title' => 'card_feature_title',
            'card_facilities_title' => '{"key":"value", "key":"value"}',
        ];
        // vendor half-yearly
        for ($i=0; $i < 4; $i++) {
            Subscription::create($dataOne);
        }

        $datatwo = [
            'subscription_user_type' => 'vendor',
            'subscription_package_type' => 'half_yearly',
            'card_symbol_icon' => 'fa fa-doller',
            'subscription_amount' => 50,
            'card_time' => 'monthy',
            'card_heading' => 'card_heading',
            'card_feature_title' => 'card_feature_title',
            'card_facilities_title' => '{"key":"value", "key":"value"}',
        ];
        for ($i=0; $i < 4; $i++) {
            Subscription::create($datatwo);
        }
        // vendor Yearly
        $datathree = [
            'subscription_user_type' => 'vendor',
            'subscription_package_type' => 'yearly',
            'card_symbol_icon' => 'fa fa-doller',
            'subscription_amount' => 50,
            'card_time' => 'monthy',
            'card_heading' => 'card_heading',
            'card_feature_title' => 'card_feature_title',
            'card_facilities_title' => '{"key":"value", "key":"value"}',
        ];
        for ($i=0; $i < 4; $i++) {
            Subscription::create($datathree);
        }

        $affilidateOne = [
            'subscription_user_type' => 'affiliate',
            'subscription_package_type' => 'monthly',
            'card_symbol_icon' => 'fa fa-doller',
            'subscription_amount' => 50,
            'card_time' => 'monthy',
            'card_heading' => 'card_heading',
            'card_feature_title' => 'card_feature_title',
            'card_facilities_title' => '{"key":"value", "key":"value"}',
        ];
        for ($i=0; $i < 4; $i++) {
            Subscription::create($affilidateOne);
        }

        $affilidateTwo = [
            'subscription_user_type' => 'affiliate',
            'subscription_package_type' => 'half_yearly',
            'card_symbol_icon' => 'fa fa-doller',
            'subscription_amount' => 50,
            'card_time' => 'monthy',
            'card_heading' => 'card_heading',
            'card_feature_title' => 'card_feature_title',
            'card_facilities_title' => '{"key":"value", "key":"value"}',
        ];
        for ($i=0; $i < 4; $i++) {
            Subscription::create($affilidateTwo);
        }

        $affilidateThree = [
            'subscription_user_type' => 'affiliate',
            'subscription_package_type' => 'yearly',
            'card_symbol_icon' => 'fa fa-doller',
            'subscription_amount' => 50,
            'card_time' => 'monthy',
            'card_heading' => 'card_heading',
            'card_feature_title' => 'card_feature_title',
            'card_facilities_title' => '{"key":"value", "key":"value"}',
        ];
        for ($i=0; $i < 4; $i++) {
            Subscription::create($affilidateThree);
        }



    }
}
