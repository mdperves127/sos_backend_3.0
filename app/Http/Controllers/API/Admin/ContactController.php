<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    public function index()
    {
        if(checkpermission('users-response') != 1){
            return $this->permissionmessage();
        }

        $contact = Contact::latest()->get();
        return response()->json([
            'status' => 200,
            'data' => $contact,
        ]);

    }

}
