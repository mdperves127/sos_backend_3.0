<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelotherserviceorderRequest;
use App\Http\Requests\CancelownserviceorderRequest;
use App\Http\Requests\ServiceOrderStatusRequest;
use App\Models\ServiceOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ServiceBuyStatusController extends Controller
{
    //
    function index(ServiceOrderStatusRequest $request)
    {
        $validateData = $request->validated();

        $serviceOrder = ServiceOrder::find($validateData['service_order_id']);
        $status = $validateData['status'];


        if ($status == 'cancel_request') {
            $serviceOrder->is_rejected = 1;
            $serviceOrder->reason = request('reason');
            $serviceOrder->rejected_user_id = auth()->id();
        }

        $serviceOrder->save();

        return $this->response('Successfull');
    }

    function cancelownserviceorderrequest(CancelownserviceorderRequest $request)
    {
        $validateData = $request->validated();

        $serviceOrder = ServiceOrder::find($validateData['service_order_id']);

        $serviceOrder->is_rejected = 0;
        $serviceOrder->reason = '';
        $serviceOrder->rejected_user_id = '';

        $serviceOrder->save();

        return $this->response('Updated successfull');
    }

    function cancelotherserviceorderrequest(CancelotherserviceorderRequest $request){
        $validateData = $request->validated();

        $serviceOrder = ServiceOrder::find($validateData['service_order_id']);

        if(request('status') == 0){
            $serviceOrder->is_rejected = 0;
            $serviceOrder->reason = '';
            $serviceOrder->rejected_user_id = '';

            $serviceOrder->save();
        }elseif(request('status') == 1){
            $serviceOrder->status = 'canceled';
            $serviceOrder->save();

            User::find($serviceOrder->user_id)->increment('balance',$serviceOrder->amount);
        }


        return $this->response('Cancelled successfully');
    }
}
