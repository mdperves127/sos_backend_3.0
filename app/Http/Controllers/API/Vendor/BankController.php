<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use Illuminate\Http\Request;

class BankController extends Controller
{
    //
    function index(){

        return response()->json([
            'status'=>200,
            'message'=>Bank::latest()->get()
        ]);

    }
}
