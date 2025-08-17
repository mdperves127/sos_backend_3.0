<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class MembershipDetailsController extends Controller
{
    function affiliatemembership()
    {
        if(checkpermission('membership') != 1){
            return $this->permissionmessage();
        }

        return   User::query()
            ->where('role_as', 3)
            ->with('vendorsubscription:id,user_id,expire_date,product_request,product_approve,service_create')
            ->latest()
            ->paginate(10);
    }
    function vendormembership()
    {
        if(checkpermission('membership') != 1){
            return $this->permissionmessage();
        }

        return   User::query()
            ->where('role_as', 2)
            ->with('vendorsubscription:id,user_id,expire_date,service_qty,product_qty,affiliate_request')
            ->latest()
            ->paginate(10);
    }
}
