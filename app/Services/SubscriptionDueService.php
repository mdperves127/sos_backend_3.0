<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;

/**
 * Class SubscriptionDueService.
 */
class SubscriptionDueService
{
    static function subscriptiondue($userid)
    {
        if (is_numeric($userid)) {
            $userSub = \App\Models\UserSubscription::on('mysql')->where('user_id', $userid)->first();
        } else {
            $userSub = \App\Models\UserSubscription::on('mysql')->where('tenant_id', $userid)->first();
        }

        if (!$userSub) {
            return 0;
        }
        if ($userSub->subscription->plan_type == 'freemium') {
            return 0;
        }

        $userdate = Carbon::parse($userSub?->expire_date);
        $currentdate = now();

        if ($userdate < now()) {
            $totaldueday =  $userdate->diffInDays($currentdate);
            $usersubscription =  $userSub->subscription;
            $userpackagetype =  $usersubscription?->subscription_package_type;

            if ($totaldueday >= 30) {
                $totaldueday = 30;
            }

            if ($userpackagetype == 'monthly') {
                $amount = ($usersubscription->subscription_amount / 30);
            } elseif ($userpackagetype == 'half_yearly') {
                $amount = ($usersubscription->subscription_amount / 180);
            } elseif ($userpackagetype == 'yearly') {
                $amount = ($usersubscription->subscription_amount / 360);
            }

            $totaldueable = ($totaldueday * $amount);
        } else {
            $totaldueable = 0;
        }
        return $totaldueable;
    }

    static function membership_credit($userid, $packageId)
    {
        if (is_numeric($userid)) {
            $usersubscription = \App\Models\UserSubscription::on('mysql')->where('user_id', $userid)->first();
        } else {
            $usersubscription = \App\Models\UserSubscription::on('mysql')->where('tenant_id', $userid)->first();
        }

        if (!$usersubscription) {
            return 0;
        }

        if ($usersubscription->subscription_id == $packageId) {
            return 0;
        }
        $mainsubscription = $usersubscription->subscription;
        if ($mainsubscription->plan_type == 'freemium') {
            return 0;
        }
        $userseelctSubscription = Subscription::on('mysql')->findOr($packageId,function(){
            return 0;
        });

        if($usersubscription->subscription_price  > $userseelctSubscription->subscription_amount){
            return 0;
        }


        $userdate = Carbon::parse($usersubscription->expire_date);
        $currentdate = now();

        if ($userdate > now()) {

            $totalday =  $currentdate->diffInDays($userdate);

            $userpackagetype =  $mainsubscription->subscription_package_type;

            if ($userpackagetype == 'monthly') {
                $amount = ($mainsubscription->subscription_amount / 30);
            } elseif ($userpackagetype == 'half_yearly') {
                $amount = ($mainsubscription->subscription_amount / 180);
            } elseif ($userpackagetype == 'yearly') {
                $amount = ($mainsubscription->subscription_amount / 360);
            }

            return $totalday * $amount;
        }
       return 0;
    }
}
