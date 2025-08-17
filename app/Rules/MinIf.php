<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class MinIf implements Rule
{

    protected $field;
    protected $value;

    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct($field, $value)
    {
        $this->field = $field;
        $this->value = $value;
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
        $conditionField = request()->get($this->field);

        if ($conditionField == $this->value) {
            return $value >= 1; // Change this condition based on your requirements
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
        //return 'The :attribute must be at least 1 when ' . $this->field . ' is ' . $this->value . '.';
        return 'The :attribute must be at least 1 when.';
    }
}
