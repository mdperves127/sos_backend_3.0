<?php

namespace App\Rules;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Contracts\Validation\Rule;

class RenewPackageId implements Rule
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
        if ( function_exists( 'tenant' ) && tenant() ) {
            // Tenant: map tenant type to subscription_user_type (merchant=vendor, dropshipper=affiliate)
            $tenantType = tenant()->type ?? 'merchant';
            $userrole   = $tenantType === 'dropshipper' ? 'affiliate' : 'vendor';
        } else {
            // Central: use user role_as (2=vendor, 3=affiliate)
            $user = User::on( 'mysql' )->find( auth()->id() );
            $userrole = userrole( $user->role_as ?? 0 );
        }

        if ( ! $userrole ) {
            return false;
        }

        $subscription = Subscription::on( 'mysql' )
            ->where( 'id', $value )
            ->where( 'subscription_user_type', $userrole )
            ->where( 'subscription_amount', '!=', 0 )
            ->first();

        return (bool) $subscription;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'You have no access this package.';
    }
}
