<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WebsiteVisitController extends Controller
{
    public function websiteVisit($tenant_id)
    {
        $userSubscription = UserSubscription::on('mysql')->where('tenant_id', $tenant_id)->first();

        $userSubscription->already_visits++;
        $userSubscription->save();
    }
}
