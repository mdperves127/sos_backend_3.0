<?php

namespace App\Services;

use App\Models\PaymentStore;
use App\Models\SupportBox;

/**
 * Class SosService.
 */
class SosService {

    static function ticketcreate( $data ) {
        $user                                 = tenant();
        $supportBox                           = new SupportBox();
        $supportBox->setConnection('mysql');
        $supportBox->tenant_id                  = tenant()->id;
        $supportBox->support_box_category_id  = $data['support_box_category_id'];
        $supportBox->support_problem_topic_id = $data['support_problem_topic_id'];
        if ( request()->hasFile( 'file' ) ) {
            $supportBox->file = uploadany_file( $data['file'], 'uploads/support/' );
        }
        $supportBox->description = $data['description'];
        $supportBox->subject     = $data['subject'];
        $supportBox->status      = "new ticket";
        $supportBox->ticket_no   = self::generateTicketNumber();
        $supportBox->save();
        return true;
    }

    static function aamarpaysubscription( $price, $info, $coupon = null ) {

        //For Extra charge
        // $setting = Settings::first();
        // if ( $setting->extra_charge_status == "on" ) {
        //     $extra_charge = extraCharge( $price, $setting->extra_charge );

        //     $price = $price + $extra_charge;
        // }

        $uniqueId          = uniqid();
        $successurl        = url( 'api/aaparpay/subscription-success' );
        $result            = AamarPayService::gateway( $price, $uniqueId, 'subscription', $successurl );
        $info['user_id']   = userid();
        $info['coupon_id'] = $coupon;
        // $info['extra_charge'] = $extra_charge; //For Extra charge

        PaymentStore::create( [
            'payment_gateway'         => 'aamarpay',
            'trxid'                   => $uniqueId,
            'payment_type'            => 'subscription',
            'info'                    => $info,
            'customer_requirement_id' => $uniqueId,
        ] );

        return response()->json( $result );
    }

    static function generateTicketNumber() {
        // Get the current timestamp
        $timestamp = time();

        // Generate a random string
        $randomString = substr( str_shuffle( "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ" ), 0, 6 );

        // Combine timestamp and random string to create a ticket number
        $ticketNumber = "TICKET-" . date( "Ymd", $timestamp ) . "-" . $randomString;

        return $ticketNumber;
    }

}
