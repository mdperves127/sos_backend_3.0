<?php

namespace App\Http\Requests;

use App\Models\Coupon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreCouponRequest extends FormRequest
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
            'name' => ['required', 'max:256', 'unique:coupons'],
            'type' => ['required',Rule::in(['flat','percentage'])],
            'amount' => ['required'],
            'commission' => ['required'],
            'commission_type' => ['required',Rule::in(['flat','percentage'])],
            'expire_date' => ['required'],
            'limitation' => ['required'],
            'tenant_id' => ['required', 'string',Rule::exists('tenants', 'id'),function($attribute,$value,$fail){
                if(request('tenant_id') != ''){
                   $data = Coupon::on('mysql')
                    ->where('tenant_id',request('tenant_id'))
                    ->exists();
                    if($data){
                        $fail('Already coupon exists for this tenant');
                    }
                }
            }],
        ];
    }

    public function failedValidation(Validator  $validator)
    {
        throw new HttpResponseException(response()->json([
            'success'   => false,
            'message'   => 'Validation errors',
            'data'      => $validator->errors()
        ]));
    }
}
