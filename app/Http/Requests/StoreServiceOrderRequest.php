<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\ServicePaymentType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreServiceOrderRequest extends FormRequest
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
            // 'user_id'=>'required|exists:users,id',
            'vendor_service_id' => ['required', 'integer', Rule::exists('vendor_services', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'service_package_id' => ['required', 'integer', Rule::exists('service_packages', 'id')->where('vendor_service_id', request('vendor_service_id'))],
            'files' => 'required|array',
            'files.*' => 'file|max:102400',
            'details'=>'required',
            'payment_type'=>['required',Rule::in('my-wallet','aamarpay'), new ServicePaymentType()]
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
