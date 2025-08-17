<?php

namespace App\Http\Controllers;

use App\Models\DollerRate;
use Illuminate\Http\Request;

class DollerRateController extends Controller
{
    //
    function index(){
        $dollerRate =  DollerRate::first();

        if($dollerRate){
            return $this->response($dollerRate);
        }else{
            return responsejson('Not available','fail');
        }
    }
}
