<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DollerRateRequest;
use App\Models\DollerRate;
use Illuminate\Http\Request;

class DollerPriceController extends Controller
{
    //
    function index(){
        $dollerPrice = DollerRate::first();
        return $this->response($dollerPrice);
    }

    function store(DollerRateRequest $request){
        DollerRate::query()->updateOrCreate([
            'id'=>1
        ],
        [
            'amount'=>request('amount')
        ]);
        return $this->response('Updated successfull');
    }

}


