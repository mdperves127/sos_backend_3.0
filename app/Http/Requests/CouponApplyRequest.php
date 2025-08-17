<?php

namespace App\Http\Requests;

use App\Models\Coupon;
use App\Rules\CouponNameExistsForDate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CouponApplyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'name' => ['required', function ($attribute, $value, $fail) {
                $coupon =  Coupon::query()
                    ->where(['name' => $value, 'status' => 'active'])
                    ->whereDate('expire_date', '>=', now())
                    ->withCount('couponused')
                    ->first();



                if ($coupon) {
                    $couponused =   $coupon->couponused()->count();
                    if ($coupon->limitation <= $couponused) {
                        return $fail('Coupon is invalid!');
                    }
                    return true;
                }
                return $fail('Coupon is invalid!');

            }]
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success'   => false,
            'message'   => 'Validation errors',
            'data'      => $validator->errors()
        ]));
    }
}
