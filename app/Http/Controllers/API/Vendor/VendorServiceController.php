<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceDeliveryRequest;
use App\Http\Requests\StoreVendorServiceRequest;
use App\Http\Requests\UpdateVendorServiceRequest;
use App\Http\Requests\VendorOrderStatusRequest;
use App\Models\ServiceCategory;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\VendorService;
use App\Services\ShowAllService;
use App\Services\Vendor\ProductService;
use Carbon\Carbon;

class VendorServiceController extends Controller {
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        $vendorService = VendorService::query()
            ->where( ['user_id' => userid()] )
            ->with( ['servicepackages', 'serviceimages'] )
            ->paginate( 10 );

        return $this->response( $vendorService );
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreVendorServiceRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store( StoreVendorServiceRequest $request ) {
        $data                 = $request->validated();
        $getmembershipdetails = getmembershipdetails();
        $user                 = User::find( auth()->id() );

        $totalcreatedservice = VendorService::where( 'user_id', userid() )->count();

        if ( ismembershipexists() != 1 ) {
            return responsejson( 'You do not have membership', 'fail' );
        }

        if ( $user->role_as == 2 ) {
            $servicecreateqty = $getmembershipdetails->service_qty;
        }
        if ( $user->role_as == 3 ) {
            $servicecreateqty = $getmembershipdetails->service_create;
        }

        if ( isactivemembership() != 1 ) {
            return responsejson( 'Membership expired!', 'fail' );
        }

        if ( $servicecreateqty <= $totalcreatedservice ) {
            // return responsejson('You can not create service more than ' . $servicecreateqty . '.', 'fail');
            return response()->json( ['message' => 'Your service limit has been reached. Please subscribe to another package with a higher limit.'] );
        }

        ProductService::store( $data );
        return $this->response( 'Success' );
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\VendorService  $vendorService
     * @return \Illuminate\Http\Response
     */
    public function show( $id ) {
        $vendorService = VendorService::where( ['user_id' => userid(), 'id' => $id] )
            ->with( ['servicepackages', 'serviceimages'] )
            ->first();

        if ( !$vendorService ) {
            return responsejson( 'Not found', 'fail' );
        }

        return $this->response( $vendorService );
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateVendorServiceRequest  $request
     * @param  \App\Models\VendorService  $vendorService
     * @return \Illuminate\Http\Response
     */
    public function update( UpdateVendorServiceRequest $request, $id ) {
        $data = $request->validated();
        ProductService::update( $data, $id );
        return $this->response( 'Updated successfull!' );
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\VendorService  $vendorService
     * @return \Illuminate\Http\Response
     */
    public function destroy( $id ) {
        $data = VendorService::where( ['user_id' => userid(), 'id' => $id] )->first();
        if ( !$data ) {
            return responsejson( 'Not found', 'fail' );
        }
        $data->delete();

        return $this->response( 'Deleted successfull!' );
    }

    function serviceorders() {
        $order = ServiceOrder::where( ['vendor_id' => userid(), 'is_paid' => 1] )
            ->when( request( 'search' ), fn( $q, $orderid ) => $q->where( 'trxid', 'like', "%{$orderid}%" ) )
            ->latest()
            ->paginate( 10 );

        return $this->response( $order );
    }

    function statusChange( VendorOrderStatusRequest $request ) {
        $validateData = $request->validated();

        $serviceOrder = ServiceOrder::find( $validateData['service_order_id'] );
        $status       = $validateData['status'];

        if ( $status != 'cancel_request' ) {
            $serviceOrder->status = $validateData['status'];
        }

        if ( request( 'status' ) == 'progress' ) {
            $time                = $serviceOrder->packagedetails->time;
            $timer               = Carbon::now()->addDay( $time );
            $serviceOrder->timer = $timer;
        }

        if ( $status == 'cancel_request' ) {
            $serviceOrder->is_rejected      = 1;
            $serviceOrder->reason           = request( 'reason' );
            $serviceOrder->rejected_user_id = auth()->id();
        }

        $serviceOrder->save();

        return $this->response( 'Updated successfull' );
    }

    function deliverytocustomer( ServiceDeliveryRequest $request ) {
        // $validateData = $request->validated();
        // $validateData
    }

    function ordersview( $id ) {
        $data = ServiceOrder::where( ['vendor_id' => userid(), 'id' => $id, 'is_paid' => 1] )->first();
        if ( !$data ) {
            return responsejson( 'Not found', 'fail' );
        }

        $order = ServiceOrder::where( ['vendor_id' => userid(), 'is_paid' => 1] )
            ->with( ['customerdetails', 'servicedetails', 'packagedetails', 'files', 'servicerating', 'orderdelivery' => function ( $query ) {
                $query->with( 'deliveryfiles' );
            }] )
            ->find( $id );

        return $this->response( $order );
    }

    function singlemyorder( $id ) {
        $data = ServiceOrder::where( ['user_id' => userid(), 'id' => $id, 'is_paid' => 1] )->first();
        if ( !$data ) {
            return responsejson( 'Not found', 'fail' );
        }

        $order = ServiceOrder::where( ['user_id' => userid(), 'is_paid' => 1] )
            ->with( ['customerdetails', 'servicedetails', 'packagedetails', 'files', 'servicerating', 'orderdelivery' => function ( $query ) {
                $query->with( 'deliveryfiles' );
            }] )
            ->find( $id );

        return $this->response( $order );
    }

    function categorysubcategory() {
        $data = ServiceCategory::on('mysql')->query()->with( 'servicesubCategories' )->get();
        return $this->response( $data );
    }

    function allservice() {
        return ShowAllService::show();
    }

    function serviceshow( $id ) {
        $service = VendorService::query()
            ->where( ['id' => $id, 'status' => 'active'] )
            ->exists();
        if ( !$service ) {
            return responsejson( 'Not found', 'fail' );
        }

        return VendorService::query()
            ->where( ['id' => $id, 'status' => 'active'] )
            ->select( 'id', 'user_id', 'service_category_id', 'service_sub_category_id', 'title', 'description', 'tags', 'image' )
            ->with( ['servicepackages', 'serviceimages', 'user:id,name,image', 'servicecategory:id,name', 'servicesubcategory:id,name'] )
            ->first();

        //   return  $vendorService = VendorService::query()
        // ->where(['id' => $id, 'status' => 'active'])
        // // ->select('id','user_id','service_category_id','service_sub_category_id','title','description','tags','image')
        // ->with(['servicepackages', 'serviceimages', 'user:id,name,image', 'servicerating.user:id,name,image','servicecategory:id,name','servicesubcategory:id,name'])
        // ->first();
    }
    function servicerating( $id ) {
        $data = VendorService::where( ['id' => $id, 'status' => 'active'] )->first()
            ->servicerating()
            ->with( ['user:id,name,image','tenant:id,company_name,owner_name'] )
            ->when(
                request( 'search' ) == 'top_review',
                function ( $query ) {
                    $query->orderBy( 'rating', 'desc' );
                },
                function ( $query ) {
                    $query->latest();
                }
            )->paginate( 10 );

        return $this->response( $data );
    }

    public function serviceCount() {
        $all     = VendorService::where( 'user_id', auth()->user()->id )->count();
        $active  = VendorService::where( 'user_id', auth()->user()->id )->where( 'status', 'active' )->count();
        $pending = VendorService::where( 'user_id', auth()->user()->id )->where( 'status', 'pending' )->count();
        return response()->json( [
            'active'  => $active,
            'pending' => $pending,
            'all'     => $all,
        ] );
    }

    public function serviceOrderCount() {
        $all       = ServiceOrder::where( 'vendor_id', auth()->user()->id )->count();
        $success   = ServiceOrder::where( 'vendor_id', auth()->user()->id )->where( 'status', 'success' )->count();
        $delivered = ServiceOrder::where( 'vendor_id', auth()->user()->id )->where( 'status', 'delivered' )->count();
        $revision  = ServiceOrder::where( 'vendor_id', auth()->user()->id )->where( 'status', 'revision' )->count();
        $pending   = ServiceOrder::where( 'vendor_id', auth()->user()->id )->where( 'status', 'pending' )->count();
        $canceled  = ServiceOrder::where( 'vendor_id', auth()->user()->id )->where( 'status', 'canceled' )->count();
        $progress  = ServiceOrder::where( 'vendor_id', auth()->user()->id )->where( 'status', 'progress' )->count();

        return response()->json( [
            'all'       => $all,
            'success'   => $success,
            'delivered' => $delivered,
            'revision'  => $revision,
            'pending'   => $pending,
            'canceled'  => $canceled,
            'progress'  => $progress,
        ] );
    }
}
