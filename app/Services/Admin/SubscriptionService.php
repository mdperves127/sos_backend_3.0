<?php

namespace App\Services\Admin;

use App\Models\Subscription;

/**
 * Class SubscriptionService.
 */
class SubscriptionService
{

    public static function update($validateData, $id)
    {
       $subscription = Subscription::find($id);
       $subscription->card_symbol_icon          = $validateData['card_symbol_icon'];
       $subscription->card_time                 = $validateData['card_time'];
       $subscription->card_heading              = $validateData['card_heading'];
       $subscription->card_facilities_title     = $validateData['card_facilities_title'];
       $subscription->subscription_amount = $validateData['subscription_amount'];
       $subscription->suggest = request('suggest');
       $subscription->save();
       return true;
    }
}
