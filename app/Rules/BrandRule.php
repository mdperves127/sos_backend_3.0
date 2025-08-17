<?php

namespace App\Rules;

use App\Models\Brand;
use Illuminate\Contracts\Validation\Rule;

class BrandRule implements Rule
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
        $ActiveBrand = Brand::where([
            'id'=>$value,
            'status'=>'active'
        ])->first();

        if($ActiveBrand){
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
        return 'Brand is not valid.';
    }
}
