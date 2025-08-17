<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactPageRequest;
use App\Models\ContactPage;
use Illuminate\Http\Request;

class ContactPageController extends Controller
{
    //
    function store(ContactPageRequest $request){
        if(checkpermission('content') != 1){
            return $this->permissionmessage();
        }

        $contactpage = ContactPage::first();
        if($contactpage){
            $contactpage->title = request('title');
            $contactpage->description = request('description');
            $contactpage->phone = request('phone');
            $contactpage->email = request('email');
            $contactpage->address = request('address');
            $contactpage->save();
        }else{

            $contactpage = new ContactPage();
            $contactpage->title = request('title');
            $contactpage->description = request('description');
            $contactpage->phone = request('phone');
            $contactpage->email = request('email');
            $contactpage->address = request('address');
            $contactpage->save();
        }
        return $this->response('Successfull!');
    }
    function index(){
        if(checkpermission('content') != 1){
            return $this->permissionmessage();
        }
        return $this->response(ContactPage::first());
    }
}
