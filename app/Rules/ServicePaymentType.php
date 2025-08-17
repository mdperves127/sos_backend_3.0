<?php

namespace App\Rules;

use App\Models\ServicePackage;
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
            $user = User::find(userid());
            $balance = $user->balance;

            $servicePackage = ServicePackage::find(request('service_package_id'));
            $price = $servicePackage->price;

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
