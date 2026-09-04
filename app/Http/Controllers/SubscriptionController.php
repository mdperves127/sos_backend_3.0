<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Support\PublicApiCache;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
   public function index(){
    $data = PublicApiCache::remember( 'subscriptions', function () {
        return Subscription::where('is_custom', 0)->get();
    } );

    return response()->json([
         'status' => 200,
         'data' => $data,
     ]);
   }
}
