<?php

namespace App\Http\Controllers\API\Vendor;

use App\Enums\Status;
use App\Models\Unit;
use App\Models\SubUnit;
use App\Models\Color;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Rules\CategoryRule;
use App\Rules\SubCategorydRule;
use App\Rules\BrandRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Models\Settings;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use App\Models\ProductImage;

class ProductController extends Controller
{

    /**
     * Create the newly created resource in for variation.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return response()->json([
            'status' => 200,
            'unit' => Unit::whereStatus('active')->get(),
            'sub_unit' => SubUnit::whereStatus('active')->get(),
            'color' => Color::whereStatus('active')->get(),
            'barcode' => barcode(12),
        ]);
    }

    /**
     * Store the newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|max:255',
            'sku' => 'required|unique:products',
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
            'image' => ['required', 'mimes:jpeg,png,jpg'],
            'images.*' => ['required', 'mimes:jpeg,png,jpg'],

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
            $validator->after(function ($validator) {
                $discount_type = request('discount_type');
                $discount_rate = request('discount_rate');
                $required_balance = "";

                if ($discount_type == 'flat') {
                    $required_balance =  $discount_rate;
                }

                if ($discount_type == 'percent') {
                    $required_balance =  (request('selling_price') / 100) * $discount_rate;
                }
                if (Settings::find(1)->is_advance == 1) {
                    if ($required_balance != '') {
                        if ($required_balance > auth()->user()->balance) {
                            $validator->errors()->add('selling_price', 'At least one product should have  a commission balance');
                        }
                    }
                }
            });
        }


        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        } else {

            $getmembershipdetails = getmembershipdetails();

            $productecreateqty = $getmembershipdetails?->product_qty;

            $totalcreatedproduct = Product::where('user_id', userid())->count();

            if (ismembershipexists() != 1) {
                return responsejson('You do not have a membership', 'fail');
            }

            if (isactivemembership() != 1) {
                return responsejson('Membership expired!', 'fail');
            }

            if ($productecreateqty <=  $totalcreatedproduct) {
                return responsejson('You can not create product more than ' . $productecreateqty . '.', 'fail');
            }



            $product = new Product();
            $product->category_id = $request->category_id;
            $product->sku = $request->sku;
            $product->subcategory_id = $request->subcategory_id;
            $product->brand_id = $request->brand_id;
            $product->user_id = Auth::user()->id;
            $product->name = $request->name;
            $product->slug = slugCreate(Product::class, $request->name);
            $product->vendor_id = vendorId();


            $product->short_description = $request->short_description;

            $product->long_description = $request->long_description;
            $product->selling_price = $request->selling_price;
            $product->original_price = $request->original_price;
            $product->qty = $request->qty;
            $product->status = Status::Pending->value;
            $product->meta_title = $request->meta_title;
            $product->meta_keyword = $request->meta_keyword; //array
            $product->meta_description = $request->meta_description;

            $product->tags  = $request->tags; //array
            $product->discount_type = $request->discount_type;
            $product->discount_rate  = $request->discount_rate;
            $product->variants = $request->variants; //array
            $product->selling_type = request('selling_type');
            $product->selling_details = request('selling_details');
            $product->advance_payment = request('advance_payment');
            $product->single_advance_payment_type = request('single_advance_payment_type');
            $product->is_connect_bulk_single = request('is_connect_bulk_single');

            if ($request->hasFile('image')) {
                $filename =   fileUpload($request->file('image'), 'uploads/product', 500, 500);
                $product->image =  $filename;
            }

            $specification = request('specification');
            $specification_ans  = request('specification_ans');

            $specificationdata = collect($specification)->map(function ($item, $key) use ($specification_ans) {
                return [
                    "specification" => $item,
                    "specification_ans" => $specification_ans[$key],
                ];
            })->toArray();

            $product->specifications = $specificationdata;
            $product->uniqid = uniqid();
            $product->save();


            $productId = $product->id;

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $imageUrl =   fileUpload($image, 'uploads/product');
                    $proimage = new ProductImage();
                    $proimage->product_id = $productId;
                    $proimage->image = $imageUrl;
                    $proimage->save();
                }
            }


            return response()->json([
                'status' => 200,
                'message' => 'Product Added Sucessfully',
            ]);
        }
    }

    /**
     * Display the resource.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Update the resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the resource from storage.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        abort(404);
    }
}
