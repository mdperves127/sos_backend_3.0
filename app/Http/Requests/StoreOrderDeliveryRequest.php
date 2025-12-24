<?php

namespace App\Http\Requests;

use App\Models\ServiceOrder;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreOrderDeliveryRequest extends FormRequest
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
            'description' => 'required',
            'files' => 'required|array',
            'files.*' => 'file|max:102400',
            'service_order_id' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    $order = ServiceOrder::on('mysql')->find($value);
                    if (!$order) {
                        $fail('The selected service order does not exist.');
                        return;
                    }
                    if (!in_array($order->status, ['progress', 'delivered', 'revision'])) {
                        $fail('The service order status must be progress, delivered, or revision.');
                    }
                },
            ],
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
