<?php

namespace App\Http\Controllers\API\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailSubscribeRequest;
use App\Models\EmailSubscribe;
use Illuminate\Http\Request;

class EmailSubscribeController extends Controller
{
    function store(EmailSubscribeRequest $request)
    {
        $data =  EmailSubscribe::where('email', request('email'))->first();
        if ($data) {
            return $this->response('Already subscribed');
        }

        EmailSubscribe::create([
            'email' => request('email')
        ]);

        return $this->response('Successfully subscribed!');
    }
}
