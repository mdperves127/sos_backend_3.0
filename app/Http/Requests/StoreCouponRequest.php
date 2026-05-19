<?php

namespace App\Http\Requests;

use App\Models\Coupon;
use App\Models\Tenant;
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
            'name' => [
                'required',
                'max:256',
                Rule::unique( Coupon::class, 'name' )->whereNull( 'deleted_at' ),
            ],
            'type' => ['required',Rule::in(['flat','percentage'])],
            'amount' => ['required'],
            'commission' => ['required'],
            'commission_type' => ['required',Rule::in(['flat','percentage'])],
            'expire_date' => ['required'],
            'limitation' => ['required'],
            'tenant_id' => ['required', 'string', Rule::exists( Tenant::class, 'id' ), function ( $attribute, $value, $fail ) {
                if ( request( 'tenant_id' ) != '' && Coupon::where( 'tenant_id', request( 'tenant_id' ) )->exists() ) {
                    $fail( 'Already coupon exists for this tenant' );
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
