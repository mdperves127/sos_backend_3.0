<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerServiceShow;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\PaymentHistoryService;
use Illuminate\Http\Request;

class ServiceOrderShowController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */

    public function index()
    {
        if (checkpermission('service-order') != 1) {
            return $this->permissionmessage();
        }

        $serviceOrderDetails  = ServiceOrder::with(['customerdetails', 'vendor', 'servicedetails'])
            ->where('is_paid', 1)
            ->latest()
            ->when(request('search'), fn ($q, $orderid) => $q->where('trxid', 'like', "%{$orderid}%"))
            ->paginate(10);
        return $this->response($serviceOrderDetails);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        if (checkpermission('service-order') != 1) {
            return $this->permissionmessage();
        }

        $data = ServiceOrder::with(['customerdetails', 'servicedetails', 'packagedetails', 'requirementsfiles', 'vendor', 'orderdelivery' => function ($query) {
            $query->with('deliveryfiles');
        }])->find($id);
        return $this->response($data);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(CustomerServiceShow $request, $id)
    {
        if (checkpermission('service-order') != 1) {
            return $this->permissionmessage();
        }
        $validatedData = $request->validated();
        $serviceOrder = ServiceOrder::find($id);
        $serviceOrder->status = $validatedData['status'];
        $serviceOrder->save();

        if ($validatedData['status'] == 'canceled') {
            User::find($serviceOrder->user_id)->increment('balance', $serviceOrder->amount);
            PaymentHistoryService::store(uniqid(), $serviceOrder->amount, 'My wallet', 'Service cancel', '+', '', $serviceOrder->user_id);
        }

        if ($validatedData['status'] == 'success') {
            User::find($serviceOrder->vendor_id)->increment('balance', $serviceOrder->amount);
            PaymentHistoryService::store(uniqid(), $serviceOrder->amount, 'My wallet', 'Service sell', '+', '', $serviceOrder->vendor_id);
        }

        return $this->response('Updated successful');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
