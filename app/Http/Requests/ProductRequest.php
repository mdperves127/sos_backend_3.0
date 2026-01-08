<?php

namespace App\Http\Requests;

use App\Models\Cart;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest {
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize() {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules() {
        return [
            'payment_type'           => ['required', Rule::in( ['aamarpay', 'my-wallet', 'COD'] )],
            'cart_id'                => ['required', function ( $attribute, $value, $fail ) {

                $cart = Cart::where( ['tenant_id' => request('tenant_id'), 'id' => request( 'cart_id' )] )->first();
                if ( !$cart ) {
                    return $fail( 'Invalid cart' );
                }

            }],
            'datas'                  => ['required', 'array'],
            'datas.*.name'           => ['required'],
            'datas.*.phone'          => ['required', 'string', 'min:10'],
            'datas.*.email'          => ['required', 'email'],
            'datas.*.city'           => ['nullable'],
            'datas.*.address'        => ['required', 'string', 'min:10'],
            'datas.*.variants'       => ['required', 'array'],
            'datas.*.variants.*.qty' => ['required', 'integer', 'min:1'],
            // 'datas.*.merchant_order_id'   => ['required', 'string', 'min:1'],
            // 'datas.*.city_id'             => ['required', 'integer', 'min:1'],
            // 'datas.*.zone_id'             => ['required', 'integer', 'min:1'],
            // 'datas.*.area_id'             => ['required', 'integer', 'min:1'],
            // 'datas.*.item_type'           => ['required', 'integer', 'min:1'],
            // 'datas.*.special_instruction' => ['nullable', 'string', 'min:1'],
            // 'datas.*.item_quantity'       => ['required', 'integer', 'min:1'],
            // 'datas.*.item_weight'         => ['float', 'integer', 'min:1'],
            // 'datas.*.item_description'    => ['string', 'integer', 'min:1'],
        ];
    }

    public function failedValidation( Validator $validator ) {
        throw new HttpResponseException( response()->json( [
            'success' => false,
            'message' => 'Validation errors',
            'data'    => $validator->errors(),
        ] ) );
    }
}
