<?php

namespace App\Rules;

use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionDueService;
use Illuminate\Contracts\Validation\Rule;

class RenewPaymentRule implements Rule
{
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {

        if ($value == 'my-wallet') {
            $user = User::find(userid());
            $userbalance = $user->balance;
            if (request('package_id')) {
                $subscriptionamount =  Subscription::find(request('package_id'))->subscription_amount;
                $subscriptiondue = SubscriptionDueService::subscriptiondue(auth()->id());

                if (convertfloat($userbalance) >= ($subscriptionamount + $subscriptiondue)) {
                    return true;
                } else {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'Not enough balance.';
    }
}
