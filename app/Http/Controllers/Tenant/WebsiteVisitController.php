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
        $website_visits = $userSubscription->website_visits;
        $already_visits = $userSubscription->already_visits;
        $userSubscription->save();
        return response()->json([
            'status' => 200,
            'message' => 'Website visit added successfully',
            'website_visits' => $website_visits,
            'already_visits' => $already_visits
        ]);
    }
}
