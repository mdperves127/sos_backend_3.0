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
use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    function users()
    {
        $user = User::query();

        $totalmember = (clone $user)->where('role_as', '!=', 1)->count();
        $totalvendor = (clone $user)->where('role_as', 2)->count();
        $totalaffiliate = (clone $user)->where('role_as', 3)->count();
        $totaluser = (clone $user)->where('role_as', 4)->count();

        return $this->response([
            'totalmember' => $totalmember,
            'totalvendor' => $totalvendor,
            'totalaffiliate' => $totalaffiliate,
            'totaluser' => $totaluser,
        ]);
    }

    function products()
    {
        $product = Product::query();

        $totalproduct = (clone $product)->count();
        $totalactiveproduct = (clone $product)->where('status', 'active')->count();
        $totalpendingproduct = (clone $product)->where('status', 'pending')->count();
        $totaleditedproduct = (clone $product)->whereHas('pendingproduct')->count();
        $totalrejectedproduct = (clone $product)->where('status', 'rejected')->count();

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
        $affiliaterequest = ProductDetails::query();

        $totalrequest = (clone $affiliaterequest)->count();
        $totalactiverequest = (clone $affiliaterequest)->where('status', 1)->count();
        $totalpendingrequest = (clone $affiliaterequest)->where('status', 2)->count();
        $totalrejectedrequest = (clone $affiliaterequest)->where('status', 3)->count();

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
