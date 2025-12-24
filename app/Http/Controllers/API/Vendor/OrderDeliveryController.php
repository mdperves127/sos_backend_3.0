<?php

namespace App\Http\Controllers\API\Vendor;

use App\Models\OrderDelivery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderDeliveryRequest;
use App\Http\Requests\UpdateOrderDeliveryRequest;
use App\Models\DeliveryFile;
use App\Models\ServiceOrder;

class OrderDeliveryController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreOrderDeliveryRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreOrderDeliveryRequest $request)
    {
        $validateData = $request->validated();

        $order = ServiceOrder::on('mysql')->find($validateData['service_order_id']);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Service order not found.'
            ], 404);
        }

        OrderDelivery::on('mysql')->where('service_order_id', $order->id)->update([
            'type' => 'inactive'
        ]);

        $orderDelivery = OrderDelivery::on('mysql')->create([
            'description' => $validateData['description'],
            'service_order_id' => $validateData['service_order_id'],
            'customer_id' => $order->user_id
        ]);

        foreach (request('files') as $file) {
            DeliveryFile::on('mysql')->create([
                'order_delivery_id' => $orderDelivery->id,
                'files' => uploadany_file($file, 'uploads/deliveryfile/')
            ]);
        }

        $order->status = 'delivered';
        $order->save();

        return $this->response('Delivery successfull!');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\OrderDelivery  $orderDelivery
     * @return \Illuminate\Http\Response
     */
    public function show(OrderDelivery $orderDelivery)
    {

    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateOrderDeliveryRequest  $request
     * @param  \App\Models\OrderDelivery  $orderDelivery
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateOrderDeliveryRequest $request, OrderDelivery $orderDelivery)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\OrderDelivery  $orderDelivery
     * @return \Illuminate\Http\Response
     */
    public function destroy(OrderDelivery $orderDelivery)
    {
        //
    }
}
