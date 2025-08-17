<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SubscriptionAlertController extends Controller
{
    //
    function index()
    {
        $user = User::find(auth()->id())?->usersubscription;

        if (!$user) {
            return '';
        }

        $userdate = Carbon::parse($user?->expire_date);
        $currentdate = now();


        if ($userdate < $currentdate) {
            return $this->response('Your membership expired renew now.');
        } elseif ($userdate < $currentdate->subDay(7)) {
            return $this->response('Your membership will expire very soon. Renew now.');
        }
        return '';
    }
}
