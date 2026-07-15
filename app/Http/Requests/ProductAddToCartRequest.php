<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\ProductDetails;
use App\Models\Tenant;
use App\Services\CrossTenantQueryService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ProductAddToCartRequest extends FormRequest {

    public function authorize() {
        return true;
    }

    protected function prepareForValidation(): void {
        if ( ! $this->isDropshipperStorefront() || ! $this->filled( 'product_id' ) ) {
            return;
        }

        $productDetail = ProductDetails::query()
            ->where( 'product_id', $this->input( 'product_id' ) )
            ->where( 'status', 1 )
            ->first();

        if ( $productDetail?->tenant_id ) {
            $this->merge( ['tenant_id' => $productDetail->tenant_id] );
        }
    }

    public function rules() {
        $getproduct = $this->resolveCartProduct();

        return [
            'product_id'      => ['required', function ( $attribute, $value, $fail ) use ( $getproduct ) {
                if ( request( 'product_id' ) != '' && ! $getproduct ) {
                    $fail( 'Product not found!' );
                }
            }],

            'purchase_type'   => ['required', function ( $attribute, $value, $fail ) use ( $getproduct ) {
                if ( request( 'purchase_type' ) != '' && $getproduct ) {
                    $selling_type = $getproduct->selling_type;
                    if ( $selling_type == null ) {
                        if ( request( 'frontend_purchase' ) == 'yes' ) {
                            return true;
                        }

                        $fail( 'No selling type found in this product' );
                    }
                    if ( $selling_type == 'both' ) {
                        $purchase_waya = ['single', 'bulk'];
                    } elseif ( $selling_type == 'bulk' ) {
                        $purchase_waya = ['bulk'];
                    } else {
                        $purchase_waya = ['single'];
                    }

                    if ( ! in_array( request( 'purchase_type' ), $purchase_waya ) ) {
                        $fail( 'Invalid purchase type.' );
                    }
                }
            }],
            'cartItems'       => [
                'array',
            ],
            'cartItems.*.qty' => [
                'integer',
                function ( $attribute, $value, $fail ) use ( $getproduct ) {
                    if ( ! $getproduct ) {
                        return;
                    }

                    if ( request( 'purchase_type' ) == 'bulk' ) {
                        if ( $getproduct->is_connect_bulk_single == 1 ) {
                            if ( (int) $getproduct->qty < (int) $value ) {
                                $fail( 'Product quantity not available!' );
                            }
                        }

                        return;
                    }

                    if ( (int) $getproduct->qty < (int) $value ) {
                        $fail( 'Product quantity not available!' );
                    }
                },
            ],
            'tenant_id'       => [
                Rule::requiredIf( fn () => ! $this->isDropshipperStorefront() ),
                'nullable',
                'string',
                Rule::exists( 'mysql.tenants', 'id' ),
                function ( $attribute, $value, $fail ) {
                    $tenantId = $this->input( 'tenant_id' );

                    if ( ! $tenantId ) {
                        if ( $this->isDropshipperStorefront() ) {
                            $fail( 'This product is not available for this store.' );
                        }

                        return;
                    }

                    $tenant = Tenant::on( 'mysql' )->find( $tenantId );

                    if ( ! $tenant ) {
                        $fail( 'Tenant not found!' );

                        return;
                    }

                    if ( $tenant->type !== 'merchant' ) {
                        $fail( 'This product is not available for this tenant!' );
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

    private function isDropshipperStorefront(): bool {
        return function_exists( 'tenant' ) && tenant() && tenant()->type === 'dropshipper';
    }

    private function resolveCartProduct(): ?object {
        if ( ! $this->filled( 'product_id' ) || ! $this->filled( 'tenant_id' ) ) {
            return null;
        }

        if ( $this->isDropshipperStorefront() ) {
            $isApproved = ProductDetails::query()
                ->where( 'product_id', $this->input( 'product_id' ) )
                ->where( 'tenant_id', $this->input( 'tenant_id' ) )
                ->where( 'status', 1 )
                ->exists();

            if ( ! $isApproved ) {
                return null;
            }
        }

        return CrossTenantQueryService::getSingleRecordFromTenant(
            $this->input( 'tenant_id' ),
            Product::class,
            function ( $query ) {
                $query->where( 'id', $this->input( 'product_id' ) )
                    ->where( 'status', 'active' );

                if ( $this->isDropshipperStorefront() ) {
                    $query->where( 'is_show_website', 1 );
                }
            }
        );
    }

}
