<?php

namespace App\Rules;

use App\Models\ServiceOrder;
use Illuminate\Contracts\Validation\Rule;

class VendorOrderStatus implements Rule
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
        $serviceOrder = ServiceOrder::find(request('service_order_id'));
        if ($serviceOrder->status == "pending") {
            return true;
        }
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'You have no access to change this status.';
    }
}
