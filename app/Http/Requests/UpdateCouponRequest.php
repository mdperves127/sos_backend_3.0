<?php

namespace App\Http\Requests;

use App\Enums\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use App\Models\Coupon;

class UpdateCouponRequest extends FormRequest
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
            'name' => ['required', 'max:256'],
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
                    ->where('id','!=',request('id'))
                    ->exists();
                    if($data){
                        $fail('Coupon already exists for this tenant');
                    }
                }
            }],
            'status'=>['required',Rule::in([Status::Active->value,Status::Deactivate->value])]
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
