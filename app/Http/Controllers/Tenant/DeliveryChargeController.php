<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCharge;
use Illuminate\Http\Request;

class DeliveryChargeController extends Controller {
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        return response()->json( [
            'status'         => 200,
            'deliveryCharge' => DeliveryCharge::latest()->select( 'id', 'area', 'charge', 'status' )->get(),
        ] );
    }
}
