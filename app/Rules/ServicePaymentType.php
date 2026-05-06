<?php

namespace App\Rules;

use App\Models\ServicePackage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Validation\Rule;

class ServicePaymentType implements Rule
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
        if($value == 'my-wallet'){
            $isTenantContext = ( function_exists( 'tenant' ) && tenant() );

            if ( $isTenantContext ) {
                $tenant = Tenant::on( 'mysql' )->find( tenant()->id );
                if ( !$tenant ) {
                    return false;
                }
                $balance = (float) ( $tenant->balance ?? 0 );
            } else {
                if ( !function_exists( 'userid' ) || !auth()->check() ) {
                    return false;
                }
                $user = User::on( 'mysql' )->find( userid() );
                if ( !$user ) {
                    return false;
                }
                $balance = (float) ( $user->balance ?? 0 );
            }

            $servicePackage = ServicePackage::on('mysql')->find(request('service_package_id'));
            if (!$servicePackage) {
                return false;
            }
            $price = (float) ($servicePackage->price ?? 0);

            if($balance >= $price){
                return true;
            }
            return false;
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
        return 'You do not have enough balance.';
    }
}
