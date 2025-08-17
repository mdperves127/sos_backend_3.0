<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductEditRequest;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;

class VendorProductController extends Controller
{
    function index()
    {
        if(checkpermission('edit-products') != 1){
            return $this->permissionmessage();
        }
        return Product::query()
            ->with('vendor')
            ->withwhereHas('pendingproduct',function($query){
                $query->select('id','product_id','is_reject');
            })
            ->latest()
            ->paginate(10);
    }


    function productview(int $id)
    {
        if(checkpermission('edit-products') != 1){
            return $this->permissionmessage();
        }

        $product = Product::query()
            ->where('id', $id)
            ->withWhereHas('pendingproduct')
            ->first();
        if (!$product) {
            return responsejson('Not found', 'fail');
        }

        return $this->response($product);
    }

    function productstatus(ProductEditRequest $request, int $id)
    {
        $product = Product::query()
            ->where('id', $id)
            ->withWhereHas('pendingproduct')
            ->first();

        if (!$product) {
            return responsejson('Not found', 'fail');
        }

        $data =  $product->pendingproduct;

        if (request('status') == 1) {
            $data->is_reject = 1;
            $data->reason = request('reason');
            $data->save();
        }
        if (request('status') == 2) {
            if($data->short_description != ''){
                $product->short_description = $data->short_description;
            }
            if($data->long_description != ''){
                $product->long_description = $data->long_description;
            }

            if($data->specifications != ''){
                $product->specifications = $data->specifications;
            }

            if($data->image != ''){
                $product->image = $data->image;
            }
            if($data->images != ''){

                foreach($data->images as $img){
                    ProductImage::create([
                        'product_id'=>$product->id,
                        'image'=>$img
                    ]);
                }

            }
            $product->save();

            $data->delete();
        }

        if(request('status') == 0){
            $data->is_reject = 0;
            $data->reason = '';
            $data->save();
        }


        return $this->response('Updated successfully');
    }
}
