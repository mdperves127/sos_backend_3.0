<?php

namespace App\Http\Requests;

use App\Services\PlatformFeeService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreSubscriptionRequest extends FormRequest
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
            'card_symbol_icon'   => 'required',
            'subscription_amount'   => 'required|numeric',
            'card_time'   => 'required|max:256',
            'card_heading'   => 'required|max:256',
            'card_feature_title'   => 'required|max:256',
            'card_facilities_title' => 'required',
            'dropship_fee_type'     => ['sometimes', 'nullable', Rule::in( PlatformFeeService::PLAN_TYPES )],
            'dropship_fee_value'    => ['sometimes', 'nullable', 'required_with:dropship_fee_type', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,2})?$/', function ( $attribute, $value, $fail ) {
                if ( $this->input( 'dropship_fee_type' ) === PlatformFeeService::TYPE_PERCENTAGE && (float) $value > 100 ) {
                    $fail( 'Percentage platform fee cannot exceed 100.' );
                }
            }],
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
