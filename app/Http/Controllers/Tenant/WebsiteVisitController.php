<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UserSubscription;

class WebsiteVisitController extends Controller
{
    public function websiteVisit()
    {
        $userSubscription = UserSubscription::on('mysql')->where('tenant_id', tenant()->id)->first();

        if($userSubscription->has_website == 'yes'){
            $userSubscription->already_visits++;
        }
        $userSubscription->save();
    }
}
