<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceRatingRequest;
use App\Models\ServiceRating;
use App\Models\VendorService;
use Illuminate\Http\Request;

class ServiceRatingController extends Controller
{

    function store(ServiceRatingRequest $request){
        $validateddata = $request->validated();

        ServiceRating::create([
            'user_id'=>auth()->id(),
            'vendor_service_id'=>request('vendor_service_id'),
            'rating'=>request('rating'),
            'service_order_id'=>request('service_order_id'),
            'comment'=>request('comment')
        ]);

        return $this->response('Rating added successfully!');
    }
}
