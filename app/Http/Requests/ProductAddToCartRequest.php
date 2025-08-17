<?php

namespace App\Http\Requests;

use App\Models\Product;
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

        if ( request( 'product_id' ) ) {
            $getproduct = Product::query()
                ->where( 'id', request( 'product_id' ) )
                ->whereHas( 'productdetails', function ( $query ) {
                    $query->where( [
                        'user_id' => auth()->id(),
                        'status'  => 1,
                    ] );
                } )
                ->withwhereHas( 'vendor', function ( $query ) {

                    $query->withwhereHas( 'usersubscription', function ( $query ) {

                        $query->where( function ( $query ) {
                            $query->whereHas( 'subscription', function ( $query ) {
                                $query->where( 'plan_type', 'freemium' );
                            } )
                                ->where( 'expire_date', '>', now() );
                        } )
                            ->orwhere( function ( $query ) {
                                $query->whereHas( 'subscription', function ( $query ) {
                                    $query->where( 'plan_type', '!=', 'freemium' );
                                } )
                                    ->where( 'expire_date', '>', now()->subMonth( 1 ) );
                            } );
                    } );
                } )
                ->where( 'status', 'active' )
                ->first();
        }

        return [
            'product_id'      => ['required', Rule::exists( 'products', 'id' ), function ( $attribute, $value, $fail ) use ( $getproduct ) {
                if ( request( 'product_id' ) != '' ) {
                    if ( !$getproduct ) {
                        $fail( 'Product not found!' );
                    }
                }
            }],

            'purchase_type'   => ['required', function ( $attribute, $value, $fail ) use ( $getproduct ) {
                if ( request( 'purchase_type' ) != '' && request( 'product_id' ) ) {
                    $selling_type = Product::find( request( 'product_id' ) )->selling_type;
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
