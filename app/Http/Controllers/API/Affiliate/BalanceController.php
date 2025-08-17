<?php

namespace App\Http\Controllers\API\Affiliate;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class BalanceController extends Controller
{
    //
    function PendingBalance(){
        $balance = Order::where('affiliator_id',auth()->user()->id)
        ->where('status',Status::Pending->value)
        ->sum('amount');
        return response()->json($balance);


    }
    function ActiveBalance(){
        $balance = Order::where('affiliator_id',auth()->user()->id)
        ->where('status',Status::Delivered->value)
        ->sum('amount');

        return response()->json($balance);
    }
}
