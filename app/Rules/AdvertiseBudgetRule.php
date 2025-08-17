<?php

namespace App\Rules;

use App\Models\DollerRate;
use App\Models\User;
use Illuminate\Contracts\Validation\Rule;

class AdvertiseBudgetRule implements Rule
{
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct()
    {
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

        $user = User::find(userid());
        $adminDalance  =  DollerRate::first()?->amount;

        if (request('paymethod') == 'my-wallet') {
            if ($user->balance >= ($value * $adminDalance) ) {
                return true;
            } else {
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
        return 'You do not have enough balance.';
    }
}
