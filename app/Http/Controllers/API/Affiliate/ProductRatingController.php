<?php

namespace App\Http\Controllers\API\Affiliate;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRatingRequest;
use App\Models\Order;
use App\Models\ProductRating;
use Illuminate\Http\Request;

class ProductRatingController extends Controller
{
    //

    function rating(ProductRatingRequest $request){
        $order = Order::find(request('order_id'));

        ProductRating::create([
            'order_id'=>$order->id,
            'product_id'=>$order->product_id,
            'user_id'=>auth()->id(),
            'rating'=>request('rating'),
            'comment'=>request('comment'),
        ]);

        return $this->response('Product rating successfull!');
    }
}
