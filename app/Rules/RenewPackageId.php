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
        $user = User::find(auth()->id());
        $userrole =  userrole($user->role_as);

        $subscription =   Subscription::query()
            ->where('id', $value)
            ->where('subscription_user_type', $userrole)
            ->where('subscription_amount', '!=', 0)
            ->first();
        if ($subscription) {
            return true;
        } else {
            return false;
        }
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
