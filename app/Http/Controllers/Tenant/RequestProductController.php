<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\VendorProductrequestRequest;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\ProductDetails;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CrossTenantQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RequestProductController extends Controller {

    function RequestPending() {
        // Check if the user is an employee and has permission
        // if ( Auth::user()->is_employee === 'yes' && employee( 'pending_request' ) === null ) {
        //     return $this->employeeMessage();
        // }

        $search  = request( 'search' );
        $product = ProductDetails::query()
            ->with( ['product' => function ( $query ) {
                $query->select( 'id', 'name', 'selling_price', 'image' )
                    ->with( 'productImage' );
            }] )
            ->where( 'vendor_id', auth()->user()->id )

            ->where( 'status', '2' )
            ->whereHas( 'product' )
            ->when( $search != '', function ( $query ) use ( $search ) {
                $query->whereHas( 'product', function ( $query ) use ( $search ) {
                    $query->where( 'name', 'like', '%' . $search . '%' );
                } )
                    ->orWhere( 'uniqid', 'like', '%' . $search . '%' );
            } )
            ->with( ['affiliator:id,name', 'vendor:id,name'] )
            ->whereHas( 'affiliator', function ( $query ) {
                $query->withCount( ['affiliatoractiveproducts' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->whereHas( 'usersubscription', function ( $query ) {
                        $query->where( 'expire_date', '>', now() );
                    } )
                    ->withSum( 'usersubscription', 'product_approve' )
                    ->having( 'affiliatoractiveproducts_count', '<=', DB::raw( 'usersubscription_sum_product_approve' ) );
            } )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'  => 200,
            'product' => $product,
        ] );
    }

    function membershipexpireactiveproduct() {
        $search  = request( 'search' );
        $product = ProductDetails::query()
            ->withWhereHas( 'product', function ( $query ) {
                $query->select( 'id', 'name', 'selling_price', 'image' )
                    ->with( 'productImage' );
            } )
            ->where( ['vendor_id' => auth()->id(), 'status' => 1] )
            ->when( $search != '', function ( $query ) use ( $search ) {
                $query->whereHas( 'product', function ( $query ) use ( $search ) {
                    $query->where( 'name', 'like', '%' . $search . '%' );
                } )
                    ->orWhere( 'uniqid', 'like', '%' . $search . '%' );
            } )
            ->with( ['affiliator:id,name', 'vendor:id,name'] )
            ->whereHas( 'affiliator', function ( $query ) {
                $query->withCount( ['affiliatoractiveproducts' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->whereHas( 'usersubscription', function ( $query ) {
                        $query->where( 'expire_date', '<=', now() );
                    } );
            } )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'  => 200,
            'product' => $product,
        ] );
    }

    function RequestView( $id ) {
        $product = ProductDetails::query()
            ->with( ['product' => function ( $query ) {
                $query->with( 'productImage' );
            }] )
            ->where( 'vendor_id', auth()->id() )
            ->whereHas( 'affiliator', function ( $query ) {
                $query->withCount( ['affiliatoractiveproducts' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->whereHas( 'usersubscription', function ( $query ) {
                        $query->where( 'expire_date', '>', now() );
                    } )
                    ->withSum( 'usersubscription', 'product_approve' )
                    ->having( 'affiliatoractiveproducts_count', '<=', DB::raw( 'usersubscription_sum_product_approve' ) );
            } )
            ->find( $id );
        if ( !$product ) {
            return $this->response( 'Not found' );
        }

        return response()->json( [
            'status'  => 200,
            'product' => $product,
        ] );
    }

    function RequestActive() {

        // Check if the user is an employee and has permission
        if ( Auth::user()->is_employee === 'yes' && employee( 'active_request' ) === null ) {
            return $this->employeeMessage();
        }

        $search  = request( 'search' );
        $product = ProductDetails::query()
            ->with( ['product' => function ( $query ) {
                $query->select( 'id', 'name', 'selling_price', 'image' )
                    ->with( 'productImage' );
            }] )
            ->where( 'vendor_id', auth()->user()->id )
            ->where( 'status', 1 )
            ->whereHas( 'product' )
            ->when( $search != '', function ( $query ) use ( $search ) {
                $query->whereHas( 'product', function ( $query ) use ( $search ) {
                    $query->where( 'name', 'like', '%' . $search . '%' );
                } )
                    ->orWhere( 'uniqid', 'like', '%' . $search . '%' );
            } )
            ->with( ['affiliator:id,name', 'vendor:id,name'] )
            ->withWhereHas( 'affiliator', function ( $query ) {
                $query->select( 'id', 'name' )->withCount( ['affiliatoractiveproducts' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->withWhereHas( 'usersubscription', function ( $query ) {
                        $query->select( 'user_id', 'chat_access' )->where( 'expire_date', '>', now() );
                    } );
            } )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'  => 200,
            'product' => $product,
        ] );
    }

    function RequestAll() {
        // Check if the user is an employee and has permission
        // if ( Auth::user()->is_employee === 'yes' && employee( 'all_request' ) === null ) {
        //     return $this->employeeMessage();
        // }

        $search = request( 'search' );

        // Get all matching ProductDetails across tenant databases
        $allProductDetails = CrossTenantQueryService::queryAllTenants(
            ProductDetails::class,
            function ( $query ) use ( $search ) {
                // Optional: limit to specific tenant id stored on rows
                $query->where( 'tenant_id', tenant()->id );

                // Handle search by joining products table
                if ( $search ) {
                    $query->leftJoin( 'products', 'product_details.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'products.name', 'like', "%{$search}%" )
                                ->orWhere( 'product_details.uniqid', 'like', "%{$search}%" );
                          } )
                          ->select( 'product_details.*' )
                          ->groupBy( 'product_details.id' );
                }

                if ( $orderId = request( 'order_id' ) ) {
                    $query->where( 'product_details.id', 'like', "%{$orderId}%" );
                }

                // Order by latest
                $query->orderBy( 'product_details.created_at', 'desc' );
            }
        );

        // Manually paginate the collection returned by queryAllTenants
        $page    = (int) request()->get( 'page', 1 );
        $perPage = 10;
        $offset  = ( $page - 1 ) * $perPage;

        $productDetails = collect( $allProductDetails );
        $paginated      = $productDetails->slice( $offset, $perPage );
        $total          = $productDetails->count();
        $lastPage       = (int) max( 1, ceil( $total / $perPage ) );

        $path        = request()->url();
        $queryParams = request()->query();
        $buildUrl    = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        $response = [
            'data'           => $paginated->values(),
            'current_page'   => $page,
            'per_page'       => $perPage,
            'total'          => $total,
            'last_page'      => $lastPage,
            'from'           => $total ? $offset + 1 : null,
            'to'             => min( $offset + $perPage, $total ),
            'path'           => $path,
            'first_page_url' => $buildUrl( 1 ),
            'last_page_url'  => $total ? $buildUrl( $lastPage ) : null,
            'prev_page_url'  => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'next_page_url'  => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'product' => $response,
        ] );
    }

    function RequestRejected() {

        // Check if the user is an employee and has permission
        if ( Auth::user()->is_employee === 'yes' && employee( 'reject_request' ) === null ) {
            return $this->employeeMessage();
        }

        $search  = request( 'search' );
        $product = ProductDetails::query()
            ->where( ['vendor_id' => auth()->id(), 'status' => 3] )
            ->withWhereHas( 'product', function ( $query ) {
                $query->select( 'id', 'name', 'selling_price', 'image' )
                    ->with( 'productImage' );
            } )
            ->when( $search != '', function ( $query ) use ( $search ) {
                $query->whereHas( 'product', function ( $query ) use ( $search ) {
                    $query->where( 'name', 'like', '%' . $search . '%' );
                } )
                    ->orWhere( 'uniqid', 'like', '%' . $search . '%' );
            } )
            ->with( ['affiliator:id,name', 'vendor:id,name'] )
            ->whereHas( 'affiliator', function ( $query ) {
                $query->withCount( ['affiliatoractiveproducts' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->whereHas( 'usersubscription', function ( $query ) {
                        $query->where( 'expire_date', '>', now() );
                    } )
                    ->withSum( 'usersubscription', 'product_approve' )
                    ->having( 'affiliatoractiveproducts_count', '<=', DB::raw( 'usersubscription_sum_product_approve' ) );
            } )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'  => 200,
            'product' => $product,
        ] );
    }

    function RequestUpdate( VendorProductrequestRequest $request, $id ) {
        $validatedData = $request->validated();
        $data          = ProductDetails::find( $id );

        if ( $data ) {

            if ( request( 'status' ) == 1 ) {
                $getmembershipdetails = getmembershipdetails();

                $affiliaterequest = $getmembershipdetails?->affiliate_request;

                $totalrequest = ProductDetails::where( ['vendor_id' => vendorId(), 'status' => 1] )->count();

                if ( ismembershipexists( vendorId() ) != 1 ) {
                    return responsejson( 'You do not have a membership', 'fail' );
                }

                if ( isactivemembership( vendorId() ) != 1 ) {
                    return responsejson( 'Membership expired!', 'fail' );
                }

                if ( $affiliaterequest <= $totalrequest ) {
                    return responsejson( 'You can not accept product request more than ' . $affiliaterequest . '.', 'fail' );
                }
            }

            $data->status = request( 'status' );
            $data->reason = request( 'reason' );
            $data->save();

            $existingConversation = Conversation::where( 'sender_id', vendorId() )
                ->where( 'receiver_id', $request->user_id )
                ->orWhere( 'sender_id', $request->user_id )
                ->where( 'receiver_id', vendorId() )
                ->first();

            if ( request( 'status' ) == 1 AND !$existingConversation ) {
                Conversation::create( [
                    'sender_id'   => vendorId(),
                    'receiver_id' => $data->user_id,
                ] );
            }

            //For Notification
            // $user = User::where('id',$data->user_id)->first();
            // $product = Product::where('id',$data->product_id)->first();
            // $text = "Your requested product " .$product->name . request('status') == 2 ? "was accepted !" : "was rejected !";
            // Notification::send($user, new AffiliateProductRequestStatusNotification($user, $text, $product));

        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'Not found',
            ] );
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'updated successfully',
        ] );
    }

    public function affiliateRequestCount() {

        $count = ProductDetails::where( 'vendor_id', auth()->user()->id )
            ->when( request( 'status' ) == 'pending', function ( $q ) {
                return $q->where( 'status', '2' );
            } )
            ->when( request( 'status' ) == 'rejected', function ( $q ) {
                return $q->where( 'status', '3' );
            } )
            ->when( request( 'status' ) == 'active', function ( $q ) {
                return $q->where( 'status', '1' );
            } )->count();
        return response()->json( [
            'status' => 200,
            'count'  => $count,
        ] );
    }

    function membershipexpireactiveproductCount() {
        $expire_request_count = ProductDetails::query()
            ->where( ['vendor_id' => auth()->id(), 'status' => 1] )
            ->whereHas( 'affiliator', function ( $query ) {
                $query->withCount( ['affiliatoractiveproducts' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->whereHas( 'usersubscription', function ( $query ) {
                        $query->where( 'expire_date', '<=', now() );
                    } );
            } )
            ->count();

        return response()->json( [
            'status'               => 200,
            'expire_request_count' => $expire_request_count,
        ] );
    }
}
