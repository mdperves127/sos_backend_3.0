<?php

namespace App\Http\Controllers;

use App\Http\Requests\RenewRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionRenewService;
use Illuminate\Http\Request;

class RenewController extends Controller
{
    function store(RenewRequest $request)
    {
     $validatedData =  $request->validated();
     return   SubscriptionRenewService::renew($validatedData);
    }
}
