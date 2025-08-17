<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailSubscribe;
use Illuminate\Http\Request;

class UserEmailSubscribeControllerList extends Controller
{
    //
    function index(){
      return  $data =  EmailSubscribe::latest()->paginate(10);
    }
}
