<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;
use App\Models\VendorService;
use App\Http\Requests\StoreVendorServiceRequest;
use App\Http\Requests\UpdateVendorServiceRequest;
use App\Services\Vendor\ProductService;
use App\Models\User;
use App\Http\Requests\VendorOrderStatusRequest;
use Carbon\Carbon;

class ServiceController extends Controller
{
    public function index() {
        $vendorService = VendorService::on('mysql')
            ->where( ['tenant_id' => tenant()->id] )
            ->with( ['servicepackages', 'serviceimages'] )
            ->paginate( 10 );

        return $this->response( $vendorService );
    }


    public function store( StoreVendorServiceRequest $request ) {
        $data                 = $request->validated();
        $getmembershipdetails = getmembershipdetails();
        $user                 = User::find( auth()->id() );

        $totalcreatedservice = VendorService::on('mysql')->where( 'tenant_id', tenant()->id )->count();

        // if ( ismembershipexists() != 1 ) {
        //     return responsejson( 'You do not have membership', 'fail' );
        // }

        // if ( $user->role_as == 2 ) {
        //     $servicecreateqty = $getmembershipdetails->service_qty;
        // }
        // if ( $user->role_as == 3 ) {
        //     $servicecreateqty = $getmembershipdetails->service_create;
        // }

        // if ( isactivemembership() != 1 ) {
        //     return responsejson( 'Membership expired!', 'fail' );
        // }

        // if ( $servicecreateqty <= $totalcreatedservice ) {
        //     // return responsejson('You can not create service more than ' . $servicecreateqty . '.', 'fail');
        //     return response()->json( ['message' => 'Your service limit has been reached. Please subscribe to another package with a higher limit.'] );
        // }

        ProductService::store( $data );
        return $this->response( 'Success' );
    }


    public function show( $id ) {
        $vendorService = VendorService::on('mysql')->where( ['tenant_id' => tenant()->id, 'id' => $id] )
            ->with( ['servicepackages', 'serviceimages'] )
            ->first();

        if ( !$vendorService ) {
            return responsejson( 'Not found', 'fail' );
        }

        return $this->response( $vendorService );
    }
    public function view( $id ) {
        $vendorService = VendorService::on('mysql')->where( ['tenant_id' => tenant()->id, 'id' => $id] )
            ->with( ['servicepackages', 'serviceimages'] )
            ->first();

        if ( !$vendorService ) {
            return responsejson( 'Not found', 'fail' );
        }

        return $this->response( $vendorService );
    }

    public function update( UpdateVendorServiceRequest $request, $id ) {
        $data = $request->validated();
        ProductService::update( $data, $id );
        return $this->response( 'Updated successfull!' );
    }

    public function edit( UpdateVendorServiceRequest $request, $id ) {
        $data = $request->validated();
        ProductService::update( $data, $id );
        return $this->response( 'Updated successfull!' );
    }

    public function destroy( $id ) {
        $data = VendorService::on('mysql')->where( ['tenant_id' => tenant()->id, 'id' => $id] )->first();
        if ( !$data ) {
            return responsejson( 'Not found', 'fail' );
        }
        $data->delete();

        return $this->response( 'Deleted successfull!' );
    }
    public function delete( $id ) {
        $data = VendorService::on('mysql')->where(['tenant_id' => tenant()->id, 'id' => $id])->first();
        if ( !$data ) {
            return responsejson( 'Not found', 'fail' );
        }
        $data->delete();

        return $this->response( 'Deleted successfull!' );
    }




    function singlemyorder( $id ) {
        $data = ServiceOrder::on('mysql')->where( ['tenant_id' => tenant()->id, 'id' => $id, 'is_paid' => 1] )->first();
        if ( !$data ) {
            return responsejson( 'Not found', 'fail' );
        }

        $order = ServiceOrder::on('mysql')->where( ['tenant_id' => tenant()->id, 'is_paid' => 1] )
            ->with( ['customerdetails', 'servicedetails', 'packagedetails', 'files', 'servicerating', 'orderdelivery' => function ( $query ) {
                $query->with( 'deliveryfiles' );
            }] )
            ->find( $id );

        return $this->response( $order );
    }



    function categorysubcategory() {
        $data = ServiceCategory::on('mysql')->with( 'servicesubCategories' )->get();
        return $this->response( $data );
    }

    function serviceorders() {
        $order = ServiceOrder::on('mysql')->where( ['tenant_id' => tenant()->id, 'is_paid' => 1] )
            ->when( request( 'search' ), fn( $q, $orderid ) => $q->where( 'trxid', 'like', "%{$orderid}%" ) )
            ->with('customerdetails', 'servicedetails', 'packagedetails', 'files', 'servicerating', 'orderdelivery')
            ->latest()
            ->paginate( 10 );

        return $this->response( $order );
    }




    function statusChange( VendorOrderStatusRequest $request ) {
        $validateData = $request->validated();

        $serviceOrder = ServiceOrder::on('mysql')->find( $validateData['service_order_id'] );
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


    function ordersview( $id ) {
        $data = ServiceOrder::on('mysql')->where( ['tenant_id' => tenant()->id, 'id' => $id, 'is_paid' => 1] )->first();
        if ( !$data ) {
            return responsejson( 'Not found', 'fail' );
        }

        $order = ServiceOrder::on('mysql')->where( ['tenant_id' => tenant()->id, 'is_paid' => 1] )
            ->with( ['customerdetails', 'servicedetails', 'packagedetails', 'files', 'servicerating', 'orderdelivery' => function ( $query ) {
                $query->with( 'deliveryfiles' );
            }] )
            ->find( $id );

        return $this->response( $order );
    }

    public function serviceCount() {
        $all     = VendorService::on('mysql')->where( 'tenant_id', tenant()->id )->count();
        $active  = VendorService::on('mysql')->where( 'tenant_id', tenant()->id )->where( 'status', 'active' )->count();
        $pending = VendorService::on('mysql')->where( 'tenant_id', tenant()->id )->where( 'status', 'pending' )->count();
        return response()->json( [
            'active'  => $active,
            'pending' => $pending,
            'all'     => $all,
        ] );
    }
    public function serviceBuyCount() {
        $all = ServiceOrder::on('mysql')->where('tenant_id', tenant()->id)->count();
        $success = ServiceOrder::on('mysql')->where('tenant_id', tenant()->id)->where('status', 'success')->count();
        $delivered = ServiceOrder::on('mysql')->where('tenant_id', tenant()->id)->where('status', 'delivered')->count();
        $revision = ServiceOrder::on('mysql')->where('tenant_id', tenant()->id)->where('status', 'revision')->count();
        $pending = ServiceOrder::on('mysql')->where('tenant_id', tenant()->id)->where(['status'=> 'pending','is_paid'=>1])->count();
        $canceled = ServiceOrder::on('mysql')->where('tenant_id', tenant()->id)->where('status', 'canceled')->count();
        $progress = ServiceOrder::on('mysql')->where('tenant_id', tenant()->id)->where('status', 'progress')->count();
        return response()->json( [
            'all' => $all,
            'success' => $success,
            'delivered' => $delivered,
            'revision' => $revision,
            'pending' => $pending,
            'canceled' => $canceled,
            'progress' => $progress,
        ] );
    }
}
