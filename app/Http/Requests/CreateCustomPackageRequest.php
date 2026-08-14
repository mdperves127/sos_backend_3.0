<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateCustomPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subscription_user_type'    => 'required|in:vendor,affiliate',
            'subscription_package_type' => 'required|in:monthly,half_yearly,yearly',
            'plan_type'                 => 'required|in:freemium,basic,premium,vip',
            'card_symbol_icon'          => 'required|string|max:256',
            'subscription_amount'       => 'required|numeric|min:0',
            'card_time'                 => 'required|string|max:256',
            'card_heading'              => 'required|string|max:256',
            'card_feature_title'        => 'required|string|max:256',
            'card_facilities_title'     => 'required',
            'suggest'                   => 'nullable|boolean',

            'service_qty'               => 'nullable|integer|min:0',
            'product_qty'               => 'nullable|integer|min:0',
            'affiliate_request'         => 'nullable|integer|min:0',
            'product_request'           => 'nullable|integer|min:0',
            'product_approve'           => 'nullable|integer|min:0',
            'service_create'            => 'nullable|integer|min:0',
            'pos_sale_qty'              => 'nullable|integer|min:0',
            'website_visits'            => 'nullable|integer|min:0',
            'chat_access'               => 'nullable|in:yes,no',
            'employee_create'           => 'nullable|in:yes,no',
            'has_website'               => 'nullable|in:yes,no',
        ];
    }

    public function failedValidation( Validator $validator )
    {
        throw new HttpResponseException( response()->json( [
            'success' => false,
            'message' => 'Validation errors',
            'data'    => $validator->errors(),
        ], 422 ) );
    }
}
