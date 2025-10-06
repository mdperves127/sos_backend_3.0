<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AdminAdvertise;
use Illuminate\Http\Request;

class AdvertiseController extends Controller
{
    function index()
    {

        $data = AdminAdvertise::on('mysql')
            ->where(['tenant_id' => tenant()->id, 'is_paid' => 1])
            ->latest()
            ->when(request('order_id'), fn ($q, $orderid) => $q->where('trxid', 'like', "%{$orderid}%"))
            ->select('id', 'campaign_name', 'campaign_objective', 'budget_amount', 'start_date', 'end_date', 'is_paid', 'created_at', 'status','unique_id')
            ->paginate(10);

        return $this->response($data);
    }
    function show($id)
    {
        $data =  AdminAdvertise::on('mysql')
            ->where(['tenant_id' => tenant()->id, 'is_paid' => 1])
            ->with('AdvertiseAudienceFile', 'advertiseLocationFiles', 'files')
            ->find($id);

        return $this->response($data);

    }
}
