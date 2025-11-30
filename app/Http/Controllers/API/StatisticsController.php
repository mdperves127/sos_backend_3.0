<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AdminAdvertise;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductDetails;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\VendorService;
use App\Models\Tenant;
use App\Services\CrossTenantQueryService;
use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    function users()
    {
        // Get vendors from tenants where type = 'merchant'
        $totalvendor = Tenant::where('type', 'merchant')->count();

        // Get affiliates from tenants where type = 'dropshipper'
        $totalaffiliate = Tenant::where('type', 'dropshipper')->count();

        // Get users from users table
        $totaluser = User::where('role_as', 4)->count();

        // Total members = users + vendors + affiliates
        $totalmember = $totaluser + $totalvendor + $totalaffiliate;

        return $this->response([
            'totalmember' => $totalmember,
            'totalvendor' => $totalvendor,
            'totalaffiliate' => $totalaffiliate,
            'totaluser' => $totaluser,
        ]);
    }

    function products()
    {
        // Query Products from all merchant tenant databases
        $allProducts = CrossTenantQueryService::queryAllTenants(
            Product::class,
            function ( $query ) {
                // No additional filtering needed, we'll count in memory
            }
        );

        // Count by status from the collection
        $totalproduct = $allProducts->count();
        $totalactiveproduct = $allProducts->where( 'status', 'active' )->count();
        $totalpendingproduct = $allProducts->where( 'status', 'pending' )->count();
        $totalrejectedproduct = $allProducts->where( 'status', 'rejected' )->count();

        // For edited products (products with pending_product), we need to query separately
        // Query products with pending_products relationship
        $productsWithPending = CrossTenantQueryService::queryAllTenants(
            Product::class,
            function ( $query ) {
                $query->leftJoin( 'pending_products', 'products.id', '=', 'pending_products.product_id' )
                      ->whereNotNull( 'pending_products.id' )
                      ->select( 'products.*' )
                      ->groupBy( 'products.id' );
            }
        );

        $totaleditedproduct = $productsWithPending->count();

        return $this->response([
            'totalproduct' => $totalproduct,
            'totalactiveproduct' => $totalactiveproduct,
            'totalpendingproduct' => $totalpendingproduct,
            'totaleditedproduct' => $totaleditedproduct,
            'totalrejectedproduct' => $totalrejectedproduct,
        ]);
    }

    function affiliaterequest()
    {
        // Query ProductDetails from all merchant tenant databases
        $allProductDetails = CrossTenantQueryService::queryAllTenants(
            ProductDetails::class,
            function ( $query ) {
                // No additional filtering needed, we'll count in memory
            }
        );

        // Count by status from the collection
        $totalrequest = $allProductDetails->count();
        $totalactiverequest = $allProductDetails->where( 'status', 1 )->count();
        $totalpendingrequest = $allProductDetails->where( 'status', 2 )->count();
        $totalrejectedrequest = $allProductDetails->where( 'status', 3 )->count();

        return $this->response([
            'totalrequest' => $totalrequest,
            'totalactiverequest' => $totalactiverequest,
            'totalpendingrequest' => $totalpendingrequest,
            'totalrejectedrequest' => $totalrejectedrequest,
        ]);
    }

    function manageproductorder(){

        $order = Order::query();
        $totalorder = (clone $order)->count();
        $totalholdorder = (clone $order)->where('status', 'hold')->count();
        $totalpendingorder = (clone $order)->where('status', 'pending')->count();
        $totalreceivedorder = (clone $order)->where('status', 'received')->count();
        $totalprogressorder = (clone $order)->where('status', 'progress')->count();
        $totaldeliveredorder = (clone $order)->where('status', 'delivered')->count();
        $totalcancelorder = (clone $order)->where('status', 'cancel')->count();

        return $this->response([
            'totalorder' => $totalorder,
            'totalholdorder' => $totalholdorder,
            'totalpendingorder' => $totalpendingorder,
            'totalreceivedorder' => $totalreceivedorder,
            'totalprogressorder' => $totalprogressorder,
            'totaldeliveredorder' => $totaldeliveredorder,
            'totalcancelorder' => $totalcancelorder,
        ]);
    }

    function manageservice(){
        $service = VendorService::query();
        $totalservice = (clone $service)->count();
        $totalactiveservice = (clone $service)->where('status', 'active')->count();
        $totalpendingservice = (clone $service)->where('status', 'pending')->count();
        $totalrejectedservice = (clone $service)->where('status', 'rejected')->count();

        return $this->response([
            'totalservice' => $totalservice,
            'totalactiveservice' => $totalactiveservice,
            'totalpendingservice' => $totalpendingservice,
            'totalrejectedservice' => $totalrejectedservice,
        ]);
    }

    function serviceorder(){
        $serviceorder = ServiceOrder::query()->where('is_paid',1);

        $totalserviceorder = (clone $serviceorder)->count();
        $totalpendingservice = (clone $serviceorder)->where('status','pending')->count();
        $totalprogressservice = (clone $serviceorder)->where('status','progress')->count();
        $totalrevisionservice = (clone $serviceorder)->where('status','revision')->count();
        $totaldeliveredservice = (clone $serviceorder)->where('status','delivered')->count();
        $totalsuccessservice = (clone $serviceorder)->where('status','success')->count();
        $totalcanceledservice = (clone $serviceorder)->where('status','canceled')->count();

        return $this->response([
            'totalserviceorder'=>$totalserviceorder,
            'totalpendingservice'=>$totalpendingservice,
            'totalprogressservice'=>$totalprogressservice,
            'totalrevisionservice'=>$totalrevisionservice,
            'totaldeliveredservice'=>$totaldeliveredservice,
            'totalsuccessservice'=>$totalsuccessservice,
            'totalcanceledservice'=>$totalcanceledservice
        ]);

    }

    function advertise(){

        $advertise = AdminAdvertise::query()->where('is_paid',1);

        $totaladvertise = (clone $advertise)->count();
        $totalprogressadvertise = (clone $advertise)->where('status','progress')->count();
        $totalpendingadvertise = (clone $advertise)->where('status','pending')->count();
        $totaldeliveredadvertise = (clone $advertise)->where('status','delivered')->count();
        $totalcanceldadvertise = (clone $advertise)->where('status','cancel')->count();

        return $this->response([
            'totaladvertise'=>$totaladvertise,
            'totalprogressadvertise'=>$totalprogressadvertise,
            'totalpendingadvertise'=>$totalpendingadvertise,
            'totaldeliveredadvertise'=>$totaldeliveredadvertise,
            'totalcanceldadvertise'=>$totalcanceldadvertise
        ]);

    }
}
