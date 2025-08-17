<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CustomerServiceStatus extends FormRequest
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
            'service_order_id'=>['required',Rule::exists('service_orders','id')->where('user_id',userid())->whereIn('status',['delivered','revision'])],
            'order_delivery_id'=>['required',Rule::exists('order_deliveries','id')->where('customer_id',userid())->where('service_order_id',request('service_order_id'))->where('type','active')],
            'status'=>['required',Rule::in(['success','revision'])],
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
