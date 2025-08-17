<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PaymentHistory;
use Illuminate\Http\Request;

class HistoryController extends Controller
{

    function index(){
        $data =  PaymentHistory::where('user_id',auth()->id())->latest()->paginate(12);
        return $data;
    }

}
