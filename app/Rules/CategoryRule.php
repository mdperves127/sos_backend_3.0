<?php

namespace App\Rules;

use App\Enums\Status;
use App\Models\Category;
use Illuminate\Contracts\Validation\Rule;

class CategoryRule implements Rule
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
        // dd($value);
        $ActiveCategory = Category::where([
            'id' => $value,
            'status' => Status::Active->value
        ])->first();

        if($ActiveCategory){
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
        return 'Category is not valid.';
    }
}
