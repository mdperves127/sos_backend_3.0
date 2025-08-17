<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
   public function index(){
    $data =  Subscription::all();
    return response()->json([
         'status' => 200,
         'data' => $data,
     ]);
   }
}
