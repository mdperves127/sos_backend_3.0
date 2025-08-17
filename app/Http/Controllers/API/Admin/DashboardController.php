<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Service\Admin\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{

    function index()
    {
        if (checkpermission('dashboard') != 1) {
            return $this->permissionmessage();
        }

        return  DashboardService::index();
    }
    function orderVsRevenue()
    {
        if (checkpermission('dashboard') != 1) {
            return $this->permissionmessage();
        }


        return  DashboardService::orderVsRevenue();
    }
    function recentOrder()
    {
        if (checkpermission('dashboard') != 1) {
            return $this->permissionmessage();
        }

        return  DashboardService::recentOrder();
    }
    function categoryStatus()
    {
        if (checkpermission('dashboard') != 1) {
            return $this->permissionmessage();
        }


        return  DashboardService::categoryStatus();
    }
}
