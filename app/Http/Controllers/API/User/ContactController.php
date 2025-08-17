<?php

namespace App\Http\Controllers\API\User;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(),[
            'first_name' => 'required',
            'last_name'  => 'required',
            'email'      => 'required|email',
            'number'     => 'required|numeric',
            'message'    => 'required',
        ]);
        if($validator->fails()){
            return response()->json([
                'status'  => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            Contact::create($request->all());
            return response()->json([
                'status' => 200,
                'message' => 'Thank you for share your Valuable Opinion With Us !!',
            ]);
        }
    }
}
