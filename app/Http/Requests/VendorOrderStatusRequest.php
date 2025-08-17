<?php

namespace App\Http\Requests;

use App\Models\ServiceOrder;
use App\Rules\VendorOrderStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class VendorOrderStatusRequest extends FormRequest
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
        $rulestatus = [
            'pending' => ['progress', 'cancel_request'],
            'progress' => ['cancel_request', 'delivered'],
            'delivered' => ['cancel_request'],
        ];

        $serviceorder = ServiceOrder::find(request('service_order_id'));

        return [
            'service_order_id' => [
                'required',
                'integer',
                Rule::exists('service_orders', 'id')
            ],
            'status' => [
                'required',
                Rule::in($serviceorder && array_key_exists($serviceorder->status, $rulestatus) ? $rulestatus[$serviceorder->status] : [])
            ],
            'reason' => 'required_if:status,cancel_request'
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
