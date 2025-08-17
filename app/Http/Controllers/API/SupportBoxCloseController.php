<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SupportBox;
use Illuminate\Http\Request;

class SupportBoxCloseController extends Controller
{
    function index(int $id){
        $supportbox = SupportBox::query()->where(['id'=>$id,'user_id'=>auth()->id()])->first();
        if(!$supportbox){
            return responsejson('Not found','fail');
        }

        $supportbox->is_close = 1;
        $supportbox->save();

        return $this->response('Ticket colse successfull!');
    }
}
