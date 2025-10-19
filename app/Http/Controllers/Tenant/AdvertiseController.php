<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AdminAdvertise;
use Illuminate\Http\Request;
use App\Http\Requests\StoreAdminAdvertiseRequest;
use App\Services\Admin\AdminAdvertiseService;

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

    public function store( StoreAdminAdvertiseRequest $request ) {
        // $validator = Validator::make( $request->all(), [

        //     'number' => 'required|string',
        // ] );
        // if ( $validator->fails() ) {
        //     return response()->json( [
        //         'status'            => 400,
        //         'validation_errors' => $validator->messages(),
        //     ] );
        // }
        $advertise = AdminAdvertiseService::create( $request->validated() );

        return $this->response( $advertise );
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
