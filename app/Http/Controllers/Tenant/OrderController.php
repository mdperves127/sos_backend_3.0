<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CrossTenantQueryService;
use App\Enums\Status;
use App\Http\Requests\ProductRequest;
use App\Services\ProductCheckoutService;

class OrderController extends Controller
{
    function guestStore( Request $request ) {
        $datas = collect( $request->input( 'datas', [] ) )->map( function ( $data ) {
            $email = isset( $data['email'] ) ? trim( (string) $data['email'] ) : '';
            $data['email'] = $email !== '' ? $email : 'guest@gmail.com';

            return $data;
        } );

        if ( $datas->isEmpty() ) {
            return responsejson( 'Checkout data is required for guest checkout.', 'fail' );
        }

        $cart = $request->filled( 'cart_id' ) ? Cart::where( 'id', $request->cart_id )->first() : null;
        $tenantId = $cart?->tenant_id ?: $request->tenant_id ?: tenant( 'id' );

        if ( !$tenantId ) {
            return responsejson( 'Tenant information is missing for guest checkout.', 'fail' );
        }

        $productId = $this->resolveGuestProductId( $request, $cart, $tenantId, $datas );

        if ( !$productId ) {
            return responsejson( 'Product information is missing for guest checkout.', 'fail' );
        }

        $product = CrossTenantQueryService::getSingleRecordFromTenant(
            $tenantId,
            Product::class,
            function ( $query ) use ( $productId ) {
                $query->where( ['id' => $productId, 'status' => 'active'] );
            }
        );

        if ( !$product ) {
            return responsejson( 'Product currently not available!', 'fail' );
        }

        $createdGuestCart = false;
        if ( !$cart ) {
            $guestCart = $this->createGuestCheckoutCart( $request, $product, $tenantId, $datas );

            if ( isset( $guestCart['error'] ) ) {
                return responsejson( $guestCart['error'], 'fail' );
            }

            $cart = $guestCart['cart'];
            $createdGuestCart = true;
        }

        if ( $cart->purchase_type == 'single' ) {
            if ( $product->selling_type == 'bulk' ) {
                return responsejson( 'Something is wrong delete the cart.', 'fail' );
            }
        }

        $totalqty = (int) $datas->pluck( 'variants' )->collapse()->sum( 'qty' );

        if ( $totalqty < 1 ) {
            if ( $createdGuestCart ) {
                $cart->delete();
            }

            return responsejson( 'Product quantity not available!', 'fail' );
        }

        if ( $cart->purchase_type == 'single' || $product->is_connect_bulk_single == 1 ) {
            if ( $product->qty < $totalqty ) {
                if ( $createdGuestCart ) {
                    $cart->delete();
                }

                return responsejson( 'Product quantity not available!', 'fail' );
            }
        }

        if ( $product->status == Status::Pending->value ) {
            if ( $createdGuestCart ) {
                $cart->delete();
            }

            return responsejson( 'The product under construction!', 'fail' );
        }

        $response = ProductCheckoutService::store( $cart->id, $product->id, $totalqty, 0, $datas->toArray(), 'aamarpay', $tenantId );

        if ( $createdGuestCart && method_exists( $response, 'getContent' ) ) {
            $payload = json_decode( $response->getContent(), true );

            if ( ( $payload['status'] ?? null ) !== 200 ) {
                Cart::where( 'id', $cart->id )->delete();
            }
        }

        return $response;
    }

    function store( ProductRequest $request ) {

        $cart = Cart::where('id', $request->cart_id)->first();

            if ( !$cart || !$cart->tenant_id ) {
                return responsejson( 'Cart not found or missing tenant information', 'fail' );
            }

        // Get product from cart's tenant database
        $product = CrossTenantQueryService::getSingleRecordFromTenant(
            $cart->tenant_id,
            Product::class,
            function ( $query ) use ( $cart ) {
                $query->where( ['id' => $cart->product_id, 'status' => 'active'] );
            }
        );

        if ( !$product ) {
            return responsejson( 'Product currently not available!' );
        }

        if ( $cart->purchase_type == 'single' ) {
            if ( $product->selling_type == 'bulk' ) {
                return responsejson( 'Something is wrong delete the cart.', 'fail' );
            }
        }

        $datas = collect( request( 'datas' ) );

        // if ( $cart->purchase_type == 'bulk' ) {
        //     $firstaddress = $datas->first();
        //     $variants     = collect( $firstaddress )['variants'];
        //     $totalqty     = collect( $variants )->sum( 'qty' );

        //     if ( $product->is_connect_bulk_single == 1 ) {
        //         if ( $product->qty < $totalqty ) {
        //             return responsejson( 'Product quantity not available!', 'fail' );
        //         }
        //     }
        // }

        if ( $cart->purchase_type == 'single' ) { //single

            $varients = $datas->pluck( 'variants' );

            $totalqty = collect( $varients )->collapse()->sum( 'qty' );

            if ( $product->qty < $totalqty ) {
                return responsejson( 'Product quantity not available!', 'fail' );
            }
        }

        if ( $product->status == Status::Pending->value ) {
            return responsejson( 'The product under construction!', 'fail' );
        }

        $uservarients = collect( request()->datas )->pluck( 'variants' )->collapse();

        // if ( $product->variants != '' ) {
        //     if ( ( $cart->purchase_type != 'bulk' ) && ( $product->is_connect_bulk_single != 1 ) ) {
        //         foreach ( $uservarients as $vr ) {
        //             $data = collect( $product?->productVariant?->variants )->where( 'id', $vr['variant_id'] )->where( 'qty', '>=', $vr['qty'] )->first();
        //             if ( !$data ) {
        //                 return responsejson( 'Something is wrong. Delete the cart', 'fail' );
        //             }
        //         }
        //     }

        // }

        // Get user - using auth()->user() or auth()->id() as needed
        $user = auth()->user();

        return ProductCheckoutService::store( $cart->id, $product->id, $totalqty, $user->id, request( 'datas' ), 'aamarpay', $cart->tenant_id );

    }

    private function createGuestCheckoutCart( Request $request, $product, $tenantId, $datas ) {
        $purchaseType = $request->input( 'purchase_type' );
        $sellingType = $product->selling_type ?: 'single';

        if ( !$purchaseType ) {
            $purchaseType = $sellingType === 'bulk' ? 'bulk' : 'single';
        }

        if ( $sellingType === 'bulk' ) {
            $allowedPurchaseTypes = ['bulk'];
        } elseif ( $sellingType === 'both' ) {
            $allowedPurchaseTypes = ['single', 'bulk'];
        } else {
            $allowedPurchaseTypes = ['single'];
        }

        if ( !in_array( $purchaseType, $allowedPurchaseTypes, true ) ) {
            return ['error' => 'Invalid purchase type for guest checkout.'];
        }

        $totalqty = (int) $datas->pluck( 'variants' )->collapse()->sum( 'qty' );

        if ( $totalqty < 1 ) {
            return ['error' => 'Product quantity not available!'];
        }

        $productPrice = $product->discount_price == null ? $product->selling_price : $product->discount_price;
        $affiliateCommission = 0;
        $totalProductPrice = 0;
        $totalAffiliateCommission = 0;
        $advancePayment = 0;
        $totalAdvancePayment = 0;

        if ( $purchaseType === 'single' ) {
            if ( $product->discount_type == 'percent' ) {
                $affiliateCommission = ( $productPrice / 100 ) * $product->discount_rate;
            } else {
                $affiliateCommission = $product->discount_rate;
            }

            $totalProductPrice = $productPrice * $totalqty;
            $totalAffiliateCommission = $affiliateCommission * $totalqty;

            if ( $product->single_advance_payment_type == 'percent' ) {
                $advancePayment = ( $productPrice / 100 ) * $product->advance_payment;
            } else {
                $advancePayment = $product->advance_payment;
            }

            $totalAdvancePayment = $advancePayment * $totalqty;
        } else {
            $sellingDetails = collect( $product->selling_details ?: [] );
            $minBulkQty = $sellingDetails->min( 'min_bulk_qty' );

            if ( $minBulkQty && $totalqty < $minBulkQty ) {
                return ['error' => 'Minimum Bulk Quantity ' . $minBulkQty . '.'];
            }

            $bulkdetails = $sellingDetails
                ->filter( function ( $detail ) use ( $totalqty ) {
                    return (int) ( $detail['min_bulk_qty'] ?? 0 ) <= $totalqty;
                } )
                ->sortByDesc( function ( $detail ) {
                    return (int) ( $detail['min_bulk_qty'] ?? 0 );
                } )
                ->first();

            if ( !$bulkdetails ) {
                return ['error' => 'Bulk pricing is not available for this quantity.'];
            }

            $productPrice = $bulkdetails['min_bulk_price'] ?? 0;
            $totalProductPrice = $productPrice * $totalqty;

            if ( ( $bulkdetails['bulk_commission_type'] ?? null ) == 'percent' ) {
                $affiliateCommission = ( $productPrice / 100 ) * ( $bulkdetails['bulk_commission'] ?? 0 );
            } else {
                $affiliateCommission = $bulkdetails['bulk_commission'] ?? 0;
            }

            $totalAffiliateCommission = $affiliateCommission * $totalqty;

            if ( ( $bulkdetails['advance_payment_type'] ?? null ) == 'percent' ) {
                $advancePayment = ( $productPrice / 100 ) * ( $bulkdetails['advance_payment'] ?? 0 );
            } else {
                $advancePayment = $bulkdetails['advance_payment'] ?? 0;
            }

            $totalAdvancePayment = $advancePayment * $totalqty;
        }

        $cart = Cart::create( [
            'user_id'                    => 1,
            'product_id'                 => $product->id,
            'product_qty'                => $totalqty,
            'product_price'              => $productPrice,
            'vendor_id'                  => $product->user_id,
            'amount'                     => $affiliateCommission,
            'category_id'                => $product->category_id,
            'totalproductprice'          => $totalProductPrice,
            'total_affiliate_commission' => $totalAffiliateCommission,
            'purchase_type'              => $purchaseType,
            'advancepayment'             => $advancePayment,
            'totaladvancepayment'        => $totalAdvancePayment,
            'tenant_id'                  => $tenantId,
        ] );

        return ['cart' => $cart];
    }

    private function resolveGuestProductId( Request $request, $cart, $tenantId, $datas ) {
        if ( $cart?->product_id ) {
            return $cart->product_id;
        }

        $candidateKeys = [
            'product_id',
            'id',
            'product.id',
            'product.product_id',
            'item.id',
            'item.product_id',
            'datas.0.id',
            'datas.0.product_id',
        ];

        foreach ( $candidateKeys as $key ) {
            $value = $request->input( $key );

            if ( !empty( $value ) ) {
                return $value;
            }
        }

        $variantProductId = $datas
            ->pluck( 'id' )
            ->filter()
            ->first();

        if ( $variantProductId ) {
            return $variantProductId;
        }

        $variantProductId = $datas
            ->pluck( 'product_id' )
            ->filter()
            ->first();

        if ( $variantProductId ) {
            return $variantProductId;
        }

        $variantProductId = $datas
            ->pluck( 'variants' )
            ->collapse()
            ->pluck( 'product_id' )
            ->filter()
            ->first();

        if ( $variantProductId ) {
            return $variantProductId;
        }

        $variantId = $datas
            ->pluck( 'variants' )
            ->collapse()
            ->pluck( 'variant_id' )
            ->filter()
            ->first();

        if ( !$variantId ) {
            $variantId = $datas
                ->pluck( 'variants' )
                ->collapse()
                ->pluck( 'id' )
                ->filter()
                ->first();
        }

        if ( !$variantId ) {
            return null;
        }

        $variant = CrossTenantQueryService::getSingleRecordFromTenant(
            $tenantId,
            ProductVariant::class,
            function ( $query ) use ( $variantId ) {
                $query->where( 'id', $variantId );
            }
        );

        return $variant?->product_id;
    }
}
