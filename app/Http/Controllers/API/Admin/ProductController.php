<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Rules\BrandRule;
use App\Rules\CategoryRule;
use App\Rules\SubCategorydRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    //
    function productupdate(Request $request, int $productid)
    {
        $product = Product::find($productid);
        $vendor = User::find($product->user_id);

        if (!$product) {
            return responsejson('product not found');
        }

        $validator = Validator::make($request->all(), [


            'name' => 'required|max:255',
            'sku' => 'required|unique:products,sku,' . $productid,
            'category_id' => ['required', 'integer', 'min:1', new CategoryRule],
            'subcategory_id' => ['nullable', new SubCategorydRule],
            'qty' => ['required', 'integer', 'min:1'],
            'selling_price' => ['required', 'numeric', 'min:1'],
            'original_price' => ['required', 'numeric', 'min:1'],
            'brand_id' => ['required', 'integer', 'min:1', new BrandRule],
            'meta_keyword' => ['nullable', 'array'],
            'tags' => ['nullable', 'array'],
            'variants' => ['nullable', 'array'],
            'variants.*.qty' => ['required_with:variants', 'integer', 'min:0'],
            'image' => ['nullable', 'mimes:jpeg,png,jpg'],
            'images.*' => ['nullable', 'mimes:jpeg,png,jpg'],

            'selling_type' => ['required', Rule::in(['single', 'bulk', 'both'])],
            'advance_payment' => ['numeric', 'min:0', 'nullable'],
            'single_advance_payment_type' => [Rule::in(['flat', 'percent']), Rule::requiredIf(function () {
                return (request('advance_payment') > 0) && (in_array(request('selling_type'), ['single', 'both']));
            })],

            'selling_details' => ['required_if:selling_type,bulk,both', 'array'],
            'selling_details.*.min_bulk_qty' => ['required', 'integer', 'min:0'],
            'selling_details.*.min_bulk_price' => ['required', 'numeric', 'min:1'],
            'selling_details.*.bulk_commission' => ['numeric', 'min:0'],
            'selling_details.*.bulk_commission_type' => [Rule::in(['percent', 'flat']), 'required'],
            'selling_details.*.advance_payment' => ['present', 'numeric', 'min:0'],
            'selling_details.*.advance_payment_type' => [Rule::in(['percent', 'flat']), 'required'],

            'discount_rate' => ['required_if:selling_type,single,both', 'numeric', 'min:0'],
            'discount_type' => ['in:percent,flat', Rule::requiredIf(function () {
                return request('discount_rate') > 0;
            })],
            'is_connect_bulk_single' => [function ($attribute, $value, $fail) {
                if ((request('is_connect_bulk_single') == 1) && (request('selling_details') == 'single')) {
                    $fail('You many not active when selling type single.');
                }
            }]

        ]);

        if (request('selling_type') == 'single') {
            $validator->after(function ($validator) use ($vendor) {
                $discount_type = request('discount_type');
                $discount_rate = request('discount_rate');
                $required_balance = "";

                if ($discount_type == 'flat') {
                    $required_balance =  $discount_rate;
                }

                if ($discount_type == 'percent') {
                    $required_balance =  (request('selling_price') / 100) * $discount_rate;
                }

                if ($required_balance != '') {
                    if ($required_balance > $vendor->balance) {
                        $validator->errors()->add('selling_price', 'At least one product should have  a commission balance');
                    }
                }
            });
        }

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages(),
            ]);
        }



        if ($product) {

            $product->sku = $request->input('sku');
            $product->category_id = $request->input('category_id');
            $product->subcategory_id = $request->input('subcategory_id');
            $product->brand_id = $request->input('brand_id');
            $product->user_id = $vendor->id;

            $product->name = $request->input('name');
            $product->slug =  slugUpdate(Product::class, $request->name, $productid);
            // $product->short_description = $request->input('short_description');
            // $product->long_description = $request->input('long_description');
            $product->selling_price = $request->input('selling_price');
            $product->original_price = $request->input('original_price');
            $product->qty = $request->input('qty');
            // $product->status = $request->input('status');
            $product->meta_title = $request->input('meta_title');
            $product->meta_keyword = $request->input('meta_keyword');
            $product->meta_description = $request->input('meta_description');

            $product->tags = $request->input('tags');
            $product->discount_type = $request->discount_type;
            $product->discount_rate  = $request->discount_rate;
            $product->variants = $request->variants;
            $product->selling_type = request('selling_type');
            $product->selling_details = request('selling_details');
            $product->advance_payment = request('advance_payment');
            $product->single_advance_payment_type = request('single_advance_payment_type');
            $product->is_connect_bulk_single = request('is_connect_bulk_single');





            $specification = request('specification');
            $specification_ans  = request('specification_ans');

            $specificationdata = collect($specification)->map(function ($item, $key) use ($specification_ans) {
                return [
                    "specification" => $item,
                    "specification_ans" => $specification_ans[$key],
                ];
            })->toArray();

            $product->specifications = $specificationdata;
            $product->short_description = request('short_description');
            $product->long_description = request('long_description');

            if (request()->hasFile('image')) {
                $product->image =  fileUpload($request->file('image'), 'uploads/product');
            }


            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $imageUrl =   fileUpload($image, 'uploads/product');
                    $proimage = new ProductImage();
                    $proimage->product_id = $productid;
                    $proimage->image = $imageUrl;
                    $proimage->save();
                }
            }



            $product->update();

            return response()->json([
                'status' => 200,
                'message' => 'Product Updated Successfully',
            ]);
        }
    }
    function deleteproductimage(int $id)
    {
        $productimge = ProductImage::find($id);

        if (!$productimge) {
            return responsejson('Not found', 'fail');
        }
        $productimge->delete();

        return $this->response('Image deleted successfully');
    }
}
