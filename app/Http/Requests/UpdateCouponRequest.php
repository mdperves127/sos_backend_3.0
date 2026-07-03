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
        $couponId = $this->route( 'id' ) ?? $this->route( 'coupon' );
        if ( $couponId instanceof Coupon ) {
            $couponId = $couponId->getKey();
        }

        return [
            'name' => ['required', 'max:256'],
            'type' => ['required', Rule::in( ['flat', 'percentage'] )],
            'amount' => ['required'],
            'commission' => ['required'],
            'commission_type' => ['required', Rule::in( ['flat', 'percentage'] )],
            'expire_date' => ['required'],
            'limitation' => ['required'],
            'tenant_id' => ['required', 'string', Rule::exists( 'tenants', 'id' ), function ( $attribute, $value, $fail ) use ( $couponId ) {
                $exists = Coupon::on( 'mysql' )
                    ->where( 'tenant_id', $value )
                    ->when( $couponId, fn ( $query ) => $query->where( 'id', '!=', $couponId ) )
                    ->exists();

                if ( $exists ) {
                    $fail( 'Coupon already exists for this tenant' );
                }
            }],
            'status' => ['required', Rule::in( [Status::Active->value, Status::Deactivate->value] )],
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
