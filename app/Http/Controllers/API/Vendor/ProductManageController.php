<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\PendingProduct;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Settings;
use App\Models\User;
use App\Rules\BrandRule;
use App\Rules\CategoryRule;
use App\Rules\SubCategorydRule;
use App\Services\Vendor\VariantApiService;
use App\Service\Vendor\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProductManageController extends Controller {

    /**
     * Create the newly created resource in for variation.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function create() {
        return response()->json( [
            'status'   => 200,
            'data'     => VariantApiService::variationApi(),
            'barcode'  => barcode( 12 ),
            'settings' => Settings::select( 'add_product_tutorial' )->first(),
        ] );
    }

    public function VendorProduct() {
        return response()->json( [
            'status'  => 200,
            'product' => ProductService::index(),
        ] );
    }

    public function VendorProductCount() {
        return response()->json( [
            'status' => 200,
            'count'  => ProductService::countThis(),
        ] );
    }

    public function VendorProductStore( Request $request ) {

        $validator = Validator::make( $request->all(), [
            'name'                                   => 'required|max:255',
            'sku'                                    => 'required|unique:products',
            'alert_qty'                              => 'required',
            'category_id'                            => ['required', 'integer', 'min:1', new CategoryRule],
            'subcategory_id'                         => ['nullable', new SubCategorydRule],
            'selling_price'                          => ['required', 'numeric', 'min:1'],
            'original_price'                         => ['required', 'numeric', 'min:1'],
            'discount_price'                         => ['nullable', 'numeric', 'min:1'],
            'brand_id'                               => ['required', 'integer', 'min:1', new BrandRule],
            'warehouse_id'                           => ['required', 'integer'],
            'supplier_id'                            => ['required', 'integer'],
            'meta_keyword'                           => ['nullable', 'array'],
            'tags'                                   => ['nullable', 'array'],

            // 'image'                                  => ['required', 'mimes:jpeg,png,jpg'],
            // 'images.*'                               => ['required', 'mimes:jpeg,png,jpg'],
            'image'                                  => ['nullable', 'mimes:jpeg,png,jpg', 'max:2048'], // Max size is in kilobytes
            'images.*'                               => ['nullable', 'mimes:jpeg,png,jpg', 'max:2048'],

            // 'selling_type' => ['required', Rule::in(['single', 'bulk', 'both'])],
            'advance_payment'                        => ['numeric', 'min:0', 'nullable'],
            'single_advance_payment_type'            => [Rule::in( ['flat', 'percent'] ), Rule::requiredIf( function () {
                return ( request( 'advance_payment' ) > 0 ) && ( in_array( request( 'selling_type' ), ['single', 'both'] ) );
            } )],

            'discount_rate'                          => ['required_if:selling_type,single,both', 'numeric', 'min:0'],
            'discount_type'                          => ['in:percent,flat', Rule::requiredIf( function () {
                return request( 'discount_rate' ) > 0;
            } )],
            'is_connect_bulk_single'                 => [function ( $attribute, $value, $fail ) {
                if ( ( request( 'is_connect_bulk_single' ) == 1 ) && ( request( 'selling_details' ) == 'single' ) ) {
                    $fail( 'You many not active when selling type single.' );
                }
            }],

            //For Affiliate
            // 'qty' => ['required_if:is_affiliate,1', 'integer', new MinIf('is_affiliate', 1)],
            // 'variants' => ['nullable', 'array'],
            // 'variants.*.qty' => ['required_with:variants', 'integer', 'min:0'],

            'selling_details'                        => ['required_if:selling_type,bulk,both', 'array'],
            'selling_details.*.min_bulk_qty'         => ['required', 'integer', 'min:0'],
            'selling_details.*.min_bulk_price'       => ['required', 'numeric', 'min:1'],
            'selling_details.*.bulk_commission'      => ['numeric', 'min:0'],
            'selling_details.*.bulk_commission_type' => [Rule::in( ['percent', 'flat'] ), 'required'],
            'selling_details.*.advance_payment'      => ['present', 'numeric', 'min:0'],
            'selling_details.*.advance_payment_type' => [Rule::in( ['percent', 'flat'] ), 'required'],

        ], [
            'qty.required_if'             => "The qty field is required.",
            'selling_details.required_if' => "The selling details is required.",
            'image.mimes'                 => 'The image must be a file of type: jpeg, png, jpg.',
            'image.max'                   => 'The image may not be greater than 2MB.',
            'images.*.mimes'              => 'The images must be files of type: jpeg, png, jpg.',
            'images.*.max'                => 'The images may not be greater than 2MB.',
        ] );

        // if(request('is_affiliate') == 1)
        // {
        //     $validator = Validator::make($request->all(), [
        //      'qty' => ['required', 'integer', 'min:1'],
        //      'variants' => ['nullable', 'array'],
        //      'variants.*.qty' => ['required_with:variants', 'integer', 'min:0'],

        //     'selling_details' => ['required_if:selling_type,bulk,both', 'array'],
        //     'selling_details.*.min_bulk_qty' => ['required', 'integer', 'min:0'],
        //     'selling_details.*.min_bulk_price' => ['required', 'numeric', 'min:1'],
        //     'selling_details.*.bulk_commission' => ['numeric', 'min:0'],
        //     'selling_details.*.bulk_commission_type' => [Rule::in(['percent', 'flat']), 'required'],
        //     'selling_details.*.advance_payment' => ['present', 'numeric', 'min:0'],
        //     'selling_details.*.advance_payment_type' => [Rule::in(['percent', 'flat']), 'required'],
        //     ]);
        // }

        if ( request( 'selling_type' ) == 'single' ) {
            $validator->after( function ( $validator ) {
                $discount_type    = request( 'discount_type' );
                $discount_rate    = request( 'discount_rate' );
                $required_balance = "";

                if ( $discount_type == 'flat' ) {
                    $required_balance = $discount_rate;
                }

                if ( $discount_type == 'percent' ) {
                    $required_balance = ( request( 'selling_price' ) / 100 ) * $discount_rate;
                }
                // if ( Settings::find( 1 )->is_advance == 1 ) {
                //     if ( $required_balance != '' ) {
                //         if ( $required_balance > auth()->user()->balance ) {
                //             $validator->errors()->add( 'selling_price', 'At least one product should have  a commission balance' );
                //         }
                //     }
                // }
            } );
        }

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ] );
        } else {

            // $getmembershipdetails = getmembershipdetails();

            // $productecreateqty = $getmembershipdetails?->product_qty;

            // $totalcreatedproduct = Product::where( 'vendor_id', vendorId() )->count();

            // if ( Auth::user()->is_employee == null && ismembershipexists() != 1 ) {
            //     return responsejson( 'You do not have a membership', 'fail' );
            // }

            // if ( Auth::user()->is_employee == null && isactivemembership() != 1 ) {
            //     return responsejson( 'Membership expired!', 'fail' );
            // }

            // if ( $productecreateqty <= $totalcreatedproduct ) {
            //     return responsejson( 'You can not create product more than ' . $productecreateqty . '.', 'fail' );
            // }

            $product                 = new Product();
            $product->category_id    = $request->category_id;
            $product->sku            = $request->sku;
            $product->subcategory_id = $request->subcategory_id;
            $product->supplier_id    = $request->supplier_id;
            $product->warehouse_id   = $request->warehouse_id;
            $product->brand_id       = $request->brand_id;
            $product->user_id        = Auth::user()->id;
            $product->name           = $request->name;
            $product->slug           = slugCreate( Product::class, $request->name );
            $product->vendor_id      = vendorId();

            $product->short_description = $request->short_description;

            $product->long_description = $request->long_description;
            $product->selling_price    = $request->selling_price;
            $product->original_price   = $request->original_price;
            $product->qty              = $request->qty;
            $product->status           = $request->is_affiliate == 1 ? Status::Pending->value : Status::Active->value;
            $product->meta_title       = $request->meta_title;
            $product->meta_keyword     = $request->meta_keyword; //array
            $product->meta_description = $request->meta_description;

            $product->alert_qty      = $request->alert_qty;
            $product->exp_date       = $request->exp_date;
            $product->barcode        = $request->barcode;
            $product->warranty       = $request->warranty;
            $product->is_feature     = $request->is_feature;
            $product->is_affiliate   = $request->is_affiliate;
            $product->discount_price = $request->discount_price;
            $product->pre_order      = $request->pre_order;

            $product->discount_percentage = $request->discount_price ? percentage( $request->selling_price, $request->discount_price ) : null;

            $product->tags                        = $request->tags; //array
            $product->discount_type               = $request->discount_type;
            $product->discount_rate               = $request->discount_rate;
            $product->variants                    = $request->variants; //array
            $product->selling_type                = request( 'selling_type' );
            $product->selling_details             = request( 'selling_details' );
            $product->advance_payment             = request( 'advance_payment' );
            $product->single_advance_payment_type = request( 'single_advance_payment_type' );
            $product->is_connect_bulk_single      = request( 'is_connect_bulk_single' );

            if ( $request->hasFile( 'image' ) ) {
                $filename       = fileUpload( $request->file( 'image' ), 'uploads/product', 500, 500 );
                $product->image = $filename;
            }

            $specification     = request( 'specification' );
            $specification_ans = request( 'specification_ans' );

            $specificationdata = collect( $specification )->map( function ( $item, $key ) use ( $specification_ans ) {
                return [
                    "specification"     => $item,
                    "specification_ans" => $specification_ans[$key],
                ];
            } )->toArray();

            $product->specifications = $specificationdata;
            $product->uniqid         = uniqid();
            $product->save();

            $productId = $product->id;

            if ( $request->hasFile( 'images' ) ) {
                foreach ( $request->file( 'images' ) as $image ) {
                    $imageUrl             = fileUpload( $image, 'uploads/product' );
                    $proimage             = new ProductImage();
                    $proimage->product_id = $productId;
                    $proimage->image      = $imageUrl;
                    $proimage->save();
                }
            }

            // if ( $request->is_affiliate == 1 ) {
            //     $user = User::where( 'role_as', 1 )->first();
            //     Notification::send( $user, new ProductCreateNotification( $user, $product ) );
            // }

            return response()->json( [
                'status'  => 200,
                'message' => 'Product Added Sucessfully',
            ] );
        }
    }

    public function VendorProductEdit( $id ) {
        $userId  = vendorId();
        $product = Product::query()
            ->with( 'vendor:id,name,email', 'brand', 'category:id,name', 'subcategory:id,name', 'productImage', 'productrating.affiliate:id,name,image', 'supplier:id,supplier_name,business_name', 'warehouse:id,name' )
            ->with( 'productVariant', function ( $q ) {
                $q->select( 'id', 'product_id', 'unit_id', 'size_id', 'color_id', 'qty' )->with( 'product', 'color', 'size', 'unit' );
            } )
            ->withAvg( 'productrating', 'rating' )
            ->where( 'user_id', $userId )
            ->find( $id );

        if ( $product ) {
            return response()->json( [
                'status'  => 200,
                'product' => $product,
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Product Id Found',
            ] );
        }
    }

    public function VendorUpdateProduct( Request $request, $id ) {

        $validator = Validator::make( $request->all(), [
            'name'                                   => 'required|max:255',
            'sku'                                    => 'required|unique:products,sku,' . $id,
            'category_id'                            => ['required', 'integer', 'min:1', new CategoryRule],
            'subcategory_id'                         => ['nullable', new SubCategorydRule],
            'selling_price'                          => ['required', 'numeric', 'min:1'],
            'original_price'                         => ['required', 'numeric', 'min:1'],
            // 'discount_price'                         => ['numeric', 'min:1'],
            'brand_id'                               => ['required', 'integer', 'min:1', new BrandRule],
            'warehouse_id'                           => ['required', 'integer'],
            'supplier_id'                            => ['required', 'integer'],
            'meta_keyword'                           => ['nullable', 'array'],
            'tags'                                   => ['nullable', 'array'],

            'image'                                  => ['nullable', 'mimes:jpeg,png,jpg', 'max:2048'], // Max size is in kilobytes
            'images.*'                               => ['nullable', 'mimes:jpeg,png,jpg', 'max:2048'],

            'selling_details'                        => ['required_if:selling_type,bulk,both', 'array'],
            'advance_payment'                        => ['nullable', 'numeric', 'min:0'],
            'single_advance_payment_type'            => [Rule::in( ['flat', 'percent'] ), Rule::requiredIf( function () {
                return ( request( 'advance_payment' ) > 0 ) && ( in_array( request( 'selling_type' ), ['single', 'both'] ) );
            } )],

            'discount_rate'                          => ['required_if:selling_type,single,both', 'numeric', 'min:0'],
            'discount_type'                          => ['in:percent,flat', Rule::requiredIf( function () {
                return request( 'discount_rate' ) > 0;
            } )],
            'is_connect_bulk_single'                 => [function ( $attribute, $value, $fail ) {
                if ( ( request( 'is_connect_bulk_single' ) == 1 ) && ( request( 'selling_details' ) == 'single' ) ) {
                    $fail( 'You may not activate when selling type single.' );
                }
            }],

            // For Affiliate
            // 'qty' => ['required_if:is_affiliate,1', 'integer', new MinIf('is_affiliate', 1)],
            'variants'                               => ['nullable', 'array'],
            'variants.*.qty'                         => ['required_with:variants', 'integer', 'min:0'],
            'selling_details'                        => ['required_if:selling_type,bulk', 'array'],
            'selling_details.*.min_bulk_qty'         => ['required_if:is_affiliate,1', 'required_if:selling_type,bulk', 'integer', 'min:0'],
            'selling_details.*.min_bulk_price'       => ['required_if:is_affiliate,1', 'required_if:selling_type,bulk', 'numeric', 'min:1'],
            'selling_details.*.bulk_commission'      => ['required_if:selling_type,bulk', 'numeric', 'min:0'],
            'selling_details.*.bulk_commission_type' => ['required_if:selling_type,bulk', Rule::in( ['percent', 'flat'] )],
            'selling_details.*.advance_payment'      => ['nullable', 'numeric', 'min:0'],
            'selling_details.*.advance_payment_type' => ['required_if:selling_type,bulk', Rule::in( ['percent', 'flat'] )],

        ], [
            'qty.required_if'             => "The qty field is required.",
            'selling_details.required_if' => "The selling details is required.",
            'image.mimes'                 => 'The image must be a file of type: jpeg, png, jpg.',
            'image.max'                   => 'The image may not be greater than 2MB.',
            'images.*.mimes'              => 'The images must be files of type: jpeg, png, jpg.',
            'images.*.max'                => 'The images may not be greater than 2MB.',
        ] );

        if ( request( 'selling_type' ) == 'single' ) {
            $validator->after( function ( $validator ) {
                $discount_type    = request( 'discount_type' );
                $discount_rate    = request( 'discount_rate' );
                $required_balance = "";

                if ( $discount_type == 'flat' ) {
                    $required_balance = $discount_rate;
                }

                if ( $discount_type == 'percent' ) {
                    $required_balance = ( request( 'selling_price' ) / 100 ) * $discount_rate;
                }

                if ( Settings::on('mysql')->find( 1 )->is_advance == 1 ) {
                    if ( $required_balance != '' ) {
                        if ( $required_balance > auth()->user()->balance ) {
                            $validator->errors()->add( 'selling_price', 'At least one product should have  a commission balance' );
                        }
                    }
                }
            } );
        }

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ] );
        } else {
            $product = Product::find( $id );

            if ( $product ) {

                $product->category_id    = $request->input( 'category_id' );
                $product->sku            = $request->input( 'sku' );
                $product->subcategory_id = $request->input( 'subcategory_id' );
                $product->brand_id       = $request->input( 'brand_id' );
                $product->user_id        = auth()->id();
                $product->pre_order      = $request->pre_order;
                $product->vendor_id      = vendorId();
                $product->is_feature     = $request->is_feature;
                $product->name           = $request->input( 'name' );
                $product->slug           = slugUpdate( Product::class, $request->name, $id );
                // $product->short_description = $request->input('short_description');
                // $product->long_description = $request->input('long_description');
                $product->selling_price  = $request->input( 'selling_price' );
                $product->original_price = $request->input( 'original_price' );
                // if($request->is_affiliate == 1){
                //     $product->qty = $request->input('qty');
                // }
                $product->status           = $request->is_affiliate == 1 ? Status::Pending->value : Status::Active->value;
                $product->is_affiliate     = $request->input( 'is_affiliate' );
                $product->meta_title       = $request->input( 'meta_title' );
                $product->meta_keyword     = $request->input( 'meta_keyword' );
                $product->meta_description = $request->input( 'meta_description' );
                $product->discount_price   = $request->discount_price;

                $product->discount_percentage = $request->discount_price ? percentage( $request->selling_price, $request->discount_price ) : null;

                $product->tags                        = $request->input( 'tags' );
                $product->discount_type               = $request->discount_type;
                $product->discount_rate               = $request->discount_rate;
                $product->variants                    = $request->variants;
                $product->selling_type                = request( 'selling_type' );
                $product->selling_details             = request( 'selling_details' );
                $product->advance_payment             = request( 'advance_payment' );
                $product->single_advance_payment_type = request( 'single_advance_payment_type' );
                $product->is_connect_bulk_single      = request( 'is_connect_bulk_single' );

                $product->update();

                $specification     = request( 'specification', [] );
                $specification_ans = request( 'specification_ans', [] );

                if ( ( $product->short_description != request( 'short_description' ) ) || ( $product->long_description != request( 'long_description' ) ) || request()->hasFile( 'image' ) || request()->hasFile( 'images' ) || $product->specifications != request( 'specifications' ) ) {
                    $pendingproductdetails = PendingProduct::where( 'product_id', $product->id )->first();

                    if ( !$pendingproductdetails ) {
                        $pendingproduct = new PendingProduct();
                    } else {
                        $pendingproduct = $pendingproductdetails;
                    }

                    $pendingproduct->product_id = $product->id;
                    if ( $product->short_description != request( 'short_description' ) ) {
                        $pendingproduct->short_description = request( 'short_description' );
                    }

                    if ( $product->long_description != request( 'long_description' ) ) {
                        $pendingproduct->long_description = request( 'long_description' );
                    }

                    if ( request()->hasFile( 'image' ) ) {
                        $pendingproduct->image = fileUpload( $request->file( 'image' ), 'uploads/product' );
                    }

                    $allimages = [];
                    if ( $request->file( 'images' ) ) {
                        foreach ( $request->file( 'images' ) as $key => $image ) {
                            $allimages[] = fileUpload( $request->file( 'images' )[$key], 'uploads/product' );
                        }
                    }

                    if ( $product->specifications != request( 'specifications' ) ) {
                        // $pendingproduct->specifications =  array_map(function ($item) {
                        //     unset($item['id']);
                        //     return $item;
                        // }, request('specifications'));

                        $specification     = request( 'specifications' );
                        $specification_ans = request( 'specification_ans' );

                        $specificationdata = collect( $specification )->map( function ( $item, $key ) use ( $specification_ans ) {
                            return [
                                "specifications"    => $item,
                                "specification_ans" => $specification_ans[$key],
                            ];
                        } )->toArray();

                        $pendingproduct->specifications = $specificationdata;

                    }

                    if ( request()->has( 'images' ) ) {
                        $pendingproduct->images = $allimages;
                    }
                    $pendingproduct->save();
                }

                $product->update();

                return response()->json( [
                    'status'  => 200,
                    'message' => 'Product Updated Successfully',
                ] );
            } else {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'Product Not Found',
                ] );
            }
        }
    }

    public function VendorDelete( $id ) {
        $userId  = Auth::id();
        $product = Product::where( 'user_id', $userId )->find( $id );
        if ( $product ) {
            $product->delete();
            return response()->json( [
                'status'  => 200,
                'message' => 'Product Deleted Successfully',
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Product ID Found',
            ] );
        }
    }

    function VendorDeleteImage( $id ) {
        $image = ProductImage::find( $id );
        if ( $image ) {
            if ( File::exists( $image->image ) ) {
                File::delete( $image->image );
            }
            $image->delete();

            return response()->json( [
                'status'  => 200,
                'message' => 'Product Image Deleted Successfilly!',
            ] );
        }
    }

    function vendorproducteditrequest() {
        return $products = Product::query()
            ->where( 'user_id', auth()->id() )
            ->withwhereHas( 'pendingproduct:id,product_id,is_reject' )
            ->when( request( 'search' ), fn( $q, $name ) => $q->where( 'name', 'like', "%{$name}%" ) )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();
    }
    function vendorproducteditrequestCount() {
        $requestCount = Product::query()
            ->where( 'user_id', auth()->id() )
            ->withwhereHas( 'pendingproduct:id,product_id,is_reject' )
            ->count();
        return response()->json( [
            'status'       => 200,
            'requestCount' => $requestCount,
        ] );
    }

    function vendorproducteditrequestview( int $id ) {
        $product = Product::query()
            ->where( 'user_id', auth()->id() )
            ->withwhereHas( 'pendingproduct' )
            ->find( $id );

        if ( !$product ) {
            return $this->response( 'Product not found' );
        }

        return $product;
    }
}
