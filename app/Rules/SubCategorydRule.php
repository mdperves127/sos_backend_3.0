<?php

namespace App\Rules;

use App\Models\Subcategory;
use Illuminate\Contracts\Validation\Rule;

class SubCategorydRule implements Rule
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
        // dd(  );
        $ActiveSubCategory = Subcategory::where([
            'category_id'=>request('category_id'),
            'id'=>$value
        ])->first();

        if($ActiveSubCategory){
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
        return 'Subcategory is not valid.';
    }
}
