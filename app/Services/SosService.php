<?php

namespace App\Services;

use App\Enums\SupportBoxTicketStatus;
use App\Models\PaymentStore;
use App\Models\SupportBox;

/**
 * Class SosService.
 */
class SosService {

    static function ticketcreate( $data ) {
        $supportBox = new SupportBox();
        $supportBox->setConnection( 'mysql' );

        $supportBox->tenant_id = ( function_exists( 'tenant' ) && tenant() ) ? tenant()->id : null;
        $supportBox->user_id   = auth()->check() ? auth()->id() : 0;
        $supportBox->support_box_category_id  = $data['support_box_category_id'];
        $supportBox->support_problem_topic_id = $data['support_problem_topic_id'];
        if ( request()->hasFile( 'file' ) ) {
            $supportBox->file = uploadany_file( $data['file'], 'uploads/support/' );
        }
        $supportBox->description = $data['description'];
        $supportBox->subject     = $data['subject'];
        $supportBox->status      = SupportBoxTicketStatus::NewTicket->value;
        $supportBox->ticket_no   = self::generateTicketNumber();
        $supportBox->save();
        return true;
    }

    static function aamarpaysubscription( $price, $info, $coupon = null ) {
        $uniqueId    = uniqid();
        $successurl  = EpsPaymentService::paymentSuccessUrl( 'subscription-success' );
        $tenant_type = function_exists( 'tenant' ) && tenant() ? 'tenant' : 'user';

        $info['user_id']   = userid();
        $info['tenant_id'] = function_exists( 'tenant' ) && tenant() ? tenant()->id : null;
        $info['coupon_id'] = $coupon;

        PaymentStore::on( 'mysql' )->create( [
            'payment_gateway'         => 'aamarpay',
            'trxid'                   => $uniqueId,
            'status'                  => 'pending',
            'payment_type'            => 'subscription',
            'info'                    => $info,
            'customer_requirement_id' => $uniqueId,
        ] );

        return AamarPayService::gateway( $price, $uniqueId, 'subscription', $successurl, $tenant_type );
    }

    static function generateTicketNumber() {
        $timestamp = time();
        $randomString = substr( str_shuffle( "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ" ), 0, 6 );

        return "TICKET-" . date( "Ymd", $timestamp ) . "-" . $randomString;
    }

}
