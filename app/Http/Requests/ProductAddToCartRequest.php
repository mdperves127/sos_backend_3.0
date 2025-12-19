<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\CrossTenantQueryService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ProductAddToCartRequest extends FormRequest {
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
        $getproduct = null;
        if ( request('product_id') && request('tenant_id') ) {
            $getproduct = CrossTenantQueryService::getSingleFromTenant(
                request('tenant_id'),
                Product::class,
                function($query){
                    $query->where('id', request('product_id'))
                        ->where('status','active');
                }
            );
        }

        return [
            'product_id'      => ['required', function ( $attribute, $value, $fail ) use ( $getproduct ) {
                if ( request( 'product_id' ) != '' ) {
                    if ( !$getproduct ) {
                        $fail( 'Product not found!' );
                    }
                }
            }],

            'purchase_type'   => ['required', function ( $attribute, $value, $fail ) use ( $getproduct ) {
                if ( request( 'purchase_type' ) != '' && $getproduct ) {
                    $selling_type = $getproduct->selling_type;
                    if ( $selling_type == null ) {
                        $fail( 'No selling type found in this product' );
                    }
                    if ( $selling_type == 'both' ) {
                        $purchase_waya = ['single', 'bulk'];
                    } elseif ( $selling_type == 'single' ) {
                        $purchase_waya = ['single'];
                    } else {
                        $purchase_waya = ['bulk'];
                    }

                    if ( in_array( request( 'purchase_type' ), $purchase_waya ) ) {
                        return true;
                    } else {
                        return $fail( 'Invalid purchase type.' );
                    }
                }
            }],
            'cartItems'       => [
                // 'required',
                'array',
            ],
            'cartItems.*.qty' => [
                // 'required',
                'integer',
                function ( $attribute, $value, $fail ) use ( $getproduct ) {

                    if ( request( 'purchase_type' ) == 'bulk' ) {
                        if ( $getproduct->is_connect_bulk_single == 1 ) {
                            if ( $getproduct->qty < $value ) {
                                $fail( 'Product quantity not available!' );
                            }
                        }
                        return true;
                    }
                    if ( $getproduct?->qty < $value ) {
                        $fail( 'Product quantity not available!' );
                    }
                },
            ],
            'tenant_id'       => ['required', 'string', Rule::exists('mysql.tenants', 'id'), function ( $attribute, $value, $fail ) use ( $getproduct ) {
                if ( request( 'tenant_id' ) != '' ) {
                    $tenant = Tenant::on('mysql')->find( request( 'tenant_id' ) );
                    if ( !$tenant ) {
                        $fail( 'Tenant not found!' );
                    }
                    if ( $tenant->type != 'dropshipper' ) {
                        $fail( 'This product is not available for this tenant!' );
                    }
                }
            }],
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
