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
            $entityId = ( function_exists( 'tenant' ) && tenant() ) ? tenant()->id : auth()->id();
            $subscriptiondue = SubscriptionDueService::subscriptiondue( $entityId );

            if ( function_exists( 'tenant' ) && tenant() ) {
                $balance = convertfloat( tenant()->balance ?? 0 );
            } else {
                $user = User::on( 'mysql' )->find( userid() );
                $balance = convertfloat( $user->balance ?? 0 );
            }

            if ( request( 'package_id' ) ) {
                $subscription = Subscription::on( 'mysql' )->find( request( 'package_id' ) );
                if ( $subscription && $balance >= ( $subscription->subscription_amount + $subscriptiondue ) ) {
                    return true;
                }
                return false;
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
