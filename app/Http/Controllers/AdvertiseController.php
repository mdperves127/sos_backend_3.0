<?php

namespace App\Http\Controllers;

use App\Models\AdminAdvertise;
use Illuminate\Http\Request;

class AdvertiseController extends Controller
{
    function index()
    {

        $data = AdminAdvertise::query()
            ->where(['user_id' => userid(), 'is_paid' => 1])
            ->latest()
            ->when(request('order_id'), fn ($q, $orderid) => $q->where('trxid', 'like', "%{$orderid}%"))
            ->select('id', 'campaign_name', 'campaign_objective', 'budget_amount', 'start_date', 'end_date', 'is_paid', 'created_at', 'status','unique_id')
            ->paginate(10);

        return $this->response($data);
    }

    function show($id)
    {
        $data =  AdminAdvertise::query()
            ->where(['user_id' => userid(), 'is_paid' => 1])
            ->with('AdvertiseAudienceFile', 'advertiseLocationFiles', 'files')
            ->find($id);

        return $this->response($data);

    }


    public function advertiseCount() {
        $all  = AdminAdvertise::where('user_id', auth()->user()->id)->count();
        $pending  = AdminAdvertise::where('user_id', auth()->user()->id)->where('is_paid',1)->where('status', 'pending')->count();
        $progress  = AdminAdvertise::where('user_id', auth()->user()->id)->where('status', 'progress')->count();
        $delivered  = AdminAdvertise::where('user_id', auth()->user()->id)->where('status', 'delivered')->count();
        $cancel  = AdminAdvertise::where('user_id', auth()->user()->id)->where('status', 'cancel')->count();
        return response()->json([
            'pending' => $pending,
            'progress' => $progress,
            'delivered' => $delivered,
            'cancel' => $cancel,
            'all' => $all,
        ]);
    }

}
