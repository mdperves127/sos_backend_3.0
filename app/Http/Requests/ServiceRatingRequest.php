<?php

namespace App\Http\Requests;

use App\Models\ServiceOrder;
use App\Models\ServiceRating;
use App\Models\VendorService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ServiceRatingRequest extends FormRequest
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
            'vendor_service_id' => ['required', Rule::exists('vendor_services', 'id')],
            'service_order_id' => ['required', function ($attribute, $value, $fail) {
                if (request('service_order_id') != null) {
                    $serviceorder = ServiceOrder::where(['user_id' => auth()->id(), 'id' => request('service_order_id'), 'status' => 'success'])->first();
                    if (!$serviceorder) {
                        $fail('Invalid');
                    }
                    $service = ServiceRating::where('service_order_id', request('service_order_id'))->exists();
                    if ($service) {
                        $fail('You can not do a rating one more time');
                    }
                }
            }],
            'rating' => ['required', 'numeric', 'min:1', 'max:5'],
            'comment' => 'required'
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
