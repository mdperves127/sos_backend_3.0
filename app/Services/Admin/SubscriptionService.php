<?php

namespace App\Services\Admin;

use App\Models\Subscription;

/**
 * Class SubscriptionService.
 */
class SubscriptionService
{
    public static function store( array $data ): Subscription
    {
        $facilities = $data['card_facilities_title'] ?? [];
        if ( is_string( $facilities ) ) {
            $decoded = json_decode( $facilities, true );
            $facilities = json_last_error() === JSON_ERROR_NONE ? $decoded : [$facilities];
        }

        return Subscription::create( [
            'subscription_user_type'    => $data['subscription_user_type'],
            'subscription_package_type' => $data['subscription_package_type'],
            'plan_type'                 => $data['plan_type'],
            'is_custom'                 => true,
            'card_symbol_icon'          => $data['card_symbol_icon'],
            'subscription_amount'       => $data['subscription_amount'],
            'card_time'                 => $data['card_time'],
            'card_heading'              => $data['card_heading'],
            'card_feature_title'        => $data['card_feature_title'],
            'card_facilities_title'     => $facilities,
            'suggest'                   => ! empty( $data['suggest'] ) ? 1 : 0,
            'service_qty'               => $data['service_qty'] ?? 0,
            'product_qty'               => $data['product_qty'] ?? 0,
            'affiliate_request'         => $data['affiliate_request'] ?? 0,
            'product_request'           => $data['product_request'] ?? 0,
            'product_approve'           => $data['product_approve'] ?? 0,
            'service_create'            => $data['service_create'] ?? 0,
            'pos_sale_qty'              => $data['pos_sale_qty'] ?? 0,
            'website_visits'            => $data['website_visits'] ?? 0,
            'chat_access'               => ( $data['chat_access'] ?? 'no' ) === 'yes' ? 'yes' : null,
            'employee_create'           => ( $data['employee_create'] ?? 'no' ) === 'yes' ? 'yes' : null,
            'has_website'               => $data['has_website'] ?? 'no',
        ] );
    }

    public static function update( $validateData, $id )
    {
        $subscription = Subscription::find( $id );
        $subscription->card_symbol_icon      = $validateData['card_symbol_icon'];
        $subscription->card_time             = $validateData['card_time'];
        $subscription->card_heading          = $validateData['card_heading'];
        $subscription->card_facilities_title = $validateData['card_facilities_title'];
        $subscription->subscription_amount   = $validateData['subscription_amount'];
        $subscription->suggest               = request( 'suggest' );
        $subscription->save();

        return true;
    }
}
