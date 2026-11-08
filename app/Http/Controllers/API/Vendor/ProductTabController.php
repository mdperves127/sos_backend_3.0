<?php

namespace App\Http\Controllers\API\Vendor;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Warehouse;
use App\Rules\BrandRule;
use App\Rules\CategoryRule;
use App\Rules\SubCategorydRule;
use App\Service\Vendor\ProductVariantService;
use App\Services\ProductPreOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductTabController extends Controller
{
    /**
     * POST /tenant-product/info
     * Create product shell (info tab only).
     */
    public function storeInfo( Request $request )
    {
        $validator = Validator::make( $request->all(), [
            'name'                 => 'required|string|max:255',
            'brand_id'             => ['required', 'integer', 'min:1', new BrandRule],
            'category_id'          => ['required', 'integer', 'min:1', new CategoryRule],
            'subcategory_id'       => ['nullable', new SubCategorydRule],
            'sku'                  => 'nullable|string|max:255|unique:products,sku',
            'short_description'    => 'nullable|string',
            'long_description'     => 'nullable|string',
            'specification'        => 'required|array|min:1',
            'specification.*'      => 'required|string',
            'specification_ans'    => 'required|array|min:1',
            'specification_ans.*'  => 'required|string',
            'meta_title'           => 'nullable|string|max:255',
            'meta_description'     => 'nullable|string',
            'meta_keyword'         => 'nullable|array',
            'image'                => ['nullable', 'mimes:jpeg,png,jpg,webp,gif', 'max:2048'],
            'images.*'             => ['nullable', 'mimes:jpeg,png,jpg,webp,gif', 'max:2048'],
        ], [
            'specification.required'     => 'At least one specification is required.',
            'specification_ans.required' => 'Specification answers are required.',
        ] );

        if ( $validator->fails() ) {
            return $this->validationError( $validator );
        }

        if ( count( (array) $request->input( 'specification', [] ) ) !== count( (array) $request->input( 'specification_ans', [] ) ) ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Please check the required fields.',
                'errors'  => [
                    'specification_ans' => ['Specification answers must match specification questions.'],
                ],
            ], 400 );
        }

        $product                      = new Product();
        $product->name                = $request->name;
        $product->slug                = slugCreate( Product::class, $request->name );
        $product->brand_id            = $request->brand_id;
        $product->category_id         = $request->category_id;
        $product->subcategory_id      = $request->subcategory_id;
        $product->sku                 = $request->filled( 'sku' ) ? $request->sku : $this->generateSku();
        $product->short_description   = $request->short_description;
        $product->long_description    = $request->long_description;
        $product->meta_title          = $request->meta_title;
        $product->meta_description    = $request->meta_description;
        $product->meta_keyword        = $request->meta_keyword;
        $product->user_id             = Auth::id();
        $product->vendor_id           = vendorId();
        $product->warehouse_id        = $request->input( 'warehouse_id' ) ?: Warehouse::query()->value( 'id' );
        $product->supplier_id         = $request->input( 'supplier_id' ) ?: null;
        $product->status              = Status::Active->value;
        $product->selling_price       = 0;
        $product->original_price      = 0;
        $product->qty                 = '0';
        $product->alert_qty           = 0;
        $product->is_show_website     = 1;
        $product->is_stock_show       = 1;
        $product->is_affiliate        = 0;
        $product->is_feature          = 0;
        $product->pre_order           = '0';
        $product->uniqid              = uniqid();
        $product->specifications      = $this->mapSpecifications(
            $request->input( 'specification', [] ),
            $request->input( 'specification_ans', [] )
        );

        if ( $request->hasFile( 'image' ) ) {
            $product->image = productImageUpload( $request->file( 'image' ) );
        }

        $product->save();

        if ( $request->hasFile( 'images' ) ) {
            foreach ( $request->file( 'images' ) as $image ) {
                $proimage             = new ProductImage();
                $proimage->product_id = $product->id;
                $proimage->image      = productImageUpload( $image );
                $proimage->save();
            }
        }

        return response()->json( [
            'status'     => 200,
            'message'    => 'Product created',
            'product_id' => $product->id,
        ] );
    }

    /**
     * POST /tenant-product/info/{id}
     */
    public function updateInfo( Request $request, $id )
    {
        $product = $this->findOwnedProduct( $id );
        if ( ! $product ) {
            return $this->notFound();
        }

        $validator = Validator::make( $request->all(), [
            'name'                 => 'required|string|max:255',
            'brand_id'             => ['required', 'integer', 'min:1', new BrandRule],
            'category_id'          => ['required', 'integer', 'min:1', new CategoryRule],
            'subcategory_id'       => ['nullable', new SubCategorydRule],
            'sku'                  => 'nullable|string|max:255|unique:products,sku,' . $id,
            'short_description'    => 'nullable|string',
            'long_description'     => 'nullable|string',
            'specification'        => 'required|array|min:1',
            'specification.*'      => 'required|string',
            'specification_ans'    => 'required|array|min:1',
            'specification_ans.*'  => 'required|string',
            'meta_title'           => 'nullable|string|max:255',
            'meta_description'     => 'nullable|string',
            'meta_keyword'         => 'nullable|array',
            'image'                => ['nullable', 'mimes:jpeg,png,jpg,webp,gif', 'max:2048'],
            'images.*'             => ['nullable', 'mimes:jpeg,png,jpg,webp,gif', 'max:2048'],
        ] );

        if ( $validator->fails() ) {
            return $this->validationError( $validator );
        }

        $product->name             = $request->name;
        $product->slug             = slugUpdate( Product::class, $request->name, $id );
        $product->brand_id         = $request->brand_id;
        $product->category_id      = $request->category_id;
        $product->subcategory_id   = $request->subcategory_id;
        if ( $request->filled( 'sku' ) ) {
            $product->sku = $request->sku;
        }
        $product->short_description = $request->short_description;
        $product->long_description  = $request->long_description;
        $product->meta_title        = $request->meta_title;
        $product->meta_description  = $request->meta_description;
        $product->meta_keyword      = $request->meta_keyword;
        $product->specifications    = $this->mapSpecifications(
            $request->input( 'specification', [] ),
            $request->input( 'specification_ans', [] )
        );

        if ( $request->hasFile( 'image' ) ) {
            $product->image = productImageUpload( $request->file( 'image' ) );
        }

        $product->save();

        if ( $request->hasFile( 'images' ) ) {
            foreach ( $request->file( 'images' ) as $image ) {
                $proimage             = new ProductImage();
                $proimage->product_id = $product->id;
                $proimage->image      = productImageUpload( $image );
                $proimage->save();
            }
        }

        return response()->json( [
            'status'     => 200,
            'message'    => 'Product info updated',
            'product_id' => $product->id,
        ] );
    }

    /**
     * POST /tenant-product/price/{id}
     */
    public function updatePrice( Request $request, $id )
    {
        $product = $this->findOwnedProduct( $id );
        if ( ! $product ) {
            return $this->notFound();
        }

        // Multipart forms send numbers as strings — coerce before validation/save.
        $variants = ProductVariantService::normalizeVariantPriceFields(
            $this->decodeArrayInput( $request->input( 'variants', [] ) )
        );

        $request->merge( [
            'original_price'     => ProductVariantService::toDecimal( $request->input( 'original_price' ) ),
            'selling_price'      => ProductVariantService::toDecimal( $request->input( 'selling_price' ) ),
            'discount_price'     => ProductVariantService::toDecimal( $request->input( 'discount_price' ) ),
            'alert_qty'          => ProductVariantService::toDecimal( $request->input( 'alert_qty' ) ),
            'variants'           => $variants,
            'variant_attributes' => $this->decodeArrayInput( $request->input( 'variant_attributes', [] ) ),
        ] );

        $hasVariants = in_array( (string) $request->input( 'has_variants', '0' ), ['1', 'true'], true );

        $validator = Validator::make( $request->all(), [
            'original_price'                          => ['required', 'numeric', 'min:0'],
            'selling_price'                           => ['required', 'numeric', 'min:0'],
            'discount_price'                          => ['nullable', 'numeric', 'min:0'],
            'alert_qty'                               => ['required', 'numeric', 'min:0'],
            'has_variants'                            => ['required', Rule::in( ['0', '1', 0, 1, true, false, 'true', 'false'] )],
            'variant_attributes'                      => ['nullable', 'array'],
            'variant_attributes.*.attribute_id'       => ['required_with:variant_attributes'],
            'variant_attributes.*.value_ids'          => ['required_with:variant_attributes', 'array', 'min:1'],
            'variant_attributes.*.sort_order'         => ['nullable', 'integer', 'min:0'],
            'variants'                                => [$hasVariants ? 'required' : 'nullable', 'array', $hasVariants ? 'min:1' : 'nullable'],
            'variants.*.id'                           => ['nullable'],
            'variants.*.variant_id'                   => ['nullable'],
            'variants.*.key'                          => ['nullable', 'string', 'max:255'],
            'variants.*.label'                        => ['nullable', 'string', 'max:255'],
            'variants.*.sku'                          => ['nullable', 'string', 'max:255'],
            'variants.*.image'                        => ['nullable', 'string', 'max:1000'],
            'variants.*.qty'                          => ['nullable', 'numeric', 'min:0'],
            'variants.*.price'                        => ['nullable', 'numeric', 'min:0'],
            'variants.*.rate'                         => ['nullable', 'numeric', 'min:0'],
            'variants.*.sort_order'                   => ['nullable', 'integer', 'min:0'],
            'variants.*.options'                      => ['nullable'],
        ] );

        if ( $validator->fails() ) {
            return $this->validationError( $validator );
        }

        $product->original_price = ProductVariantService::toDecimal( $request->input( 'original_price' ) ) ?? 0;
        $product->selling_price  = ProductVariantService::toDecimal( $request->input( 'selling_price' ) ) ?? 0;
        $product->discount_price = ProductVariantService::toDecimal( $request->input( 'discount_price' ) );
        $product->alert_qty      = ProductVariantService::toDecimal( $request->input( 'alert_qty' ) ) ?? 0;
        $product->discount_percentage = $product->discount_price !== null
            ? percentage( $product->selling_price, $product->discount_price )
            : null;

        // Capture before overwrite — form payloads often omit id/variant_id.
        $previousVariants = is_array( $product->variants ) ? $product->variants : [];

        $meta = $this->getTabMeta( $product );
        $meta['has_variants'] = $hasVariants ? 1 : 0;
        $meta['variant_attributes'] = $hasVariants
            ? array_values( (array) $request->input( 'variant_attributes', [] ) )
            : [];

        $variants = [];
        if ( $hasVariants ) {
            $variants = $this->normalizeVariantsPayload( (array) $request->input( 'variants', [] ) );
            $variants = ProductVariantService::rehydrateVariantIds( $variants, $previousVariants );

            if ( $variants === [] ) {
                return response()->json( [
                    'status'  => 400,
                    'message' => 'Please check the required fields.',
                    'errors'  => ['variants' => ['At least one variant combination is required.']],
                ], 400 );
            }

            $product->variants = $variants;
            $product->qty = (string) collect( $variants )->sum( fn ( $v ) => max( 0, (int) ( $v['qty'] ?? 0 ) ) );
        } else {
            $product->variants = [];
        }

        $this->putTabMeta( $product, $meta );
        $product->save();

        if ( $hasVariants ) {
            // Sync using the in-memory normalized payload (avoid refresh wiping unsynced cast quirks).
            $product->variants = $variants;
            ProductVariantService::syncFromProductVariantsJson( $product, null, false );
            $product->refresh();
        } else {
            // Turned off variants: clear JSON already saved; also delete product_variants rows.
            ProductVariantService::clearProductVariants( $product );
            $product->refresh();
        }

        return response()->json( [
            'status'          => 200,
            'message'         => 'Price updated',
            'product_id'      => $product->id,
            'selling_price'   => (float) $product->selling_price,
            'original_price'  => (float) $product->original_price,
            'discount_price'  => $product->discount_price !== null ? (float) $product->discount_price : null,
            'variants'        => $product->variants,
            'has_variants'    => $hasVariants,
        ] );
    }

    /**
     * POST /tenant-product/shipping/{id}
     * Persists into existing `request` JSON meta (no new product columns).
     */
    public function updateShipping( Request $request, $id )
    {
        $product = $this->findOwnedProduct( $id );
        if ( ! $product ) {
            return $this->notFound();
        }

        $isPhysical = in_array( (string) $request->input( 'is_physical_product', '0' ), ['1', 'true'], true );

        $validator = Validator::make( $request->all(), [
            'is_physical_product' => ['required', Rule::in( ['0', '1', 0, 1, true, false] )],
            'package_type_id'     => [$isPhysical ? 'required' : 'nullable'],
            'length'              => [$isPhysical ? 'required' : 'nullable', 'numeric', 'min:0'],
            'width'               => [$isPhysical ? 'required' : 'nullable', 'numeric', 'min:0'],
            'height'              => [$isPhysical ? 'required' : 'nullable', 'numeric', 'min:0'],
            'dimension_unit'      => [$isPhysical ? 'required' : 'nullable', Rule::in( ['in', 'cm', 'mm', 'm', 'ft'] )],
            'weight'              => [$isPhysical ? 'required' : 'nullable', 'numeric', 'min:0'],
            'weight_unit'         => [$isPhysical ? 'required' : 'nullable', Rule::in( ['lb', 'kg', 'g', 'oz'] )],
        ] );

        if ( $validator->fails() ) {
            return $this->validationError( $validator );
        }

        $shipping = $isPhysical
            ? [
                'is_physical_product' => 1,
                'package_type_id'     => $request->input( 'package_type_id' ),
                'length'              => (float) $request->input( 'length' ),
                'width'               => (float) $request->input( 'width' ),
                'height'              => (float) $request->input( 'height' ),
                'dimension_unit'      => $request->input( 'dimension_unit', 'in' ),
                'weight'              => (float) $request->input( 'weight' ),
                'weight_unit'         => $request->input( 'weight_unit', 'lb' ),
            ]
            : [
                'is_physical_product' => 0,
                'package_type_id'     => null,
                'length'              => null,
                'width'               => null,
                'height'              => null,
                'dimension_unit'      => 'in',
                'weight'              => null,
                'weight_unit'         => 'lb',
            ];

        $meta = $this->getTabMeta( $product );
        $meta['shipping'] = $shipping;
        $this->putTabMeta( $product, $meta );
        $product->save();

        return response()->json( [
            'status'     => 200,
            'message'    => 'Shipping updated',
            'product_id' => $product->id,
        ] );
    }

    /**
     * POST /tenant-product/affiliate/{id}
     */
    public function updateAffiliate( Request $request, $id )
    {
        $product = $this->findOwnedProduct( $id );
        if ( ! $product ) {
            return $this->notFound();
        }

        $isAffiliate = in_array( (string) $request->input( 'is_affiliate', '0' ), ['1', 'true'], true );

        $rules = [
            'is_affiliate'         => ['required', Rule::in( ['0', '1', 0, 1, true, false] )],
            'dropshipper_message'  => ['nullable', 'string'],
        ];

        if ( $isAffiliate ) {
            $rules = array_merge( $rules, [
                'selling_type'                           => ['required', Rule::in( ['single', 'bulk', 'both'] )],
                'is_connect_bulk_single'                 => ['nullable', Rule::in( ['0', '1', 0, 1] )],
                'advance_payment'                        => ['nullable', 'numeric', 'min:0'],
                'single_advance_payment_type'            => ['nullable', Rule::in( ['flat', 'percent'] )],
                'discount_rate'                          => ['nullable', 'numeric', 'min:0'],
                'discount_type'                          => ['nullable', Rule::in( ['flat', 'percent'] )],
                'selling_details'                        => ['required_if:selling_type,bulk,both', 'array'],
                'selling_details.*.min_bulk_qty'         => ['required_with:selling_details', 'integer', 'min:0'],
                'selling_details.*.min_bulk_price'       => ['required_with:selling_details', 'numeric', 'min:0'],
                'selling_details.*.bulk_commission'      => ['nullable', 'numeric', 'min:0'],
                'selling_details.*.bulk_commission_type' => ['nullable', Rule::in( ['flat', 'percent'] )],
                'selling_details.*.advance_payment'      => ['nullable', 'numeric', 'min:0'],
                'selling_details.*.advance_payment_type' => ['nullable', Rule::in( ['flat', 'percent'] )],
                'market_place_brand_id'                  => ['nullable'],
                'market_place_category_id'               => ['nullable'],
                'market_place_subcategory_id'            => ['nullable'],
            ] );
        }

        $validator = Validator::make( $request->all(), $rules );

        if ( $validator->fails() ) {
            return $this->validationError( $validator );
        }

        $product->is_affiliate = $isAffiliate ? 1 : 0;
        $product->dropshipper_message = $request->input( 'dropshipper_message' );

        if ( $isAffiliate ) {
            $product->selling_type                = $request->input( 'selling_type' );
            $product->is_connect_bulk_single      = $request->input( 'is_connect_bulk_single', 0 );
            $product->advance_payment             = $request->input( 'advance_payment' );
            $product->single_advance_payment_type = $request->input( 'single_advance_payment_type' );
            $product->discount_rate               = $request->input( 'discount_rate' );
            $product->discount_type               = $request->input( 'discount_type' );
            $product->selling_details             = $request->input( 'selling_details' );
            $product->market_place_brand_id       = $request->input( 'market_place_brand_id' );
            $product->market_place_category_id    = $request->input( 'market_place_category_id' );
            $product->market_place_subcategory_id = $request->input( 'market_place_subcategory_id' );
            $product->status                      = Status::Pending->value;
        } else {
            $product->status = Status::Active->value;
        }

        $product->save();

        return response()->json( [
            'status'     => 200,
            'message'    => 'Affiliate updated',
            'product_id' => $product->id,
        ] );
    }

    /**
     * POST /tenant-product/options/{id}
     */
    public function updateOptions( Request $request, $id )
    {
        $product = $this->findOwnedProduct( $id );
        if ( ! $product ) {
            return $this->notFound();
        }

        $validator = Validator::make( $request->all(), [
            'is_feature'                 => ['nullable', Rule::in( ['0', '1', 0, 1] )],
            'is_show_website'            => ['nullable', Rule::in( ['0', '1', 0, 1] )],
            'is_stock_show'              => ['nullable', Rule::in( ['0', '1', 0, 1] )],
            'pre_order'                  => ['nullable', Rule::in( ['0', '1', 0, 1] )],
            'expected_delivery_date'     => ['nullable', 'date'],
            'pre_order_quantity_limit'   => ['nullable', 'integer', 'min:1'],
            'pre_order_advance_amount'   => ['nullable', 'numeric', 'min:0'],
            'pre_order_payment_options'  => ['nullable', Rule::in( ['advance', 'full', 'both'] )],
            'pre_order_status'           => ['nullable', Rule::in( ['active', 'closed'] )],
        ] );

        if ( $validator->fails() ) {
            return $this->validationError( $validator );
        }

        if ( $request->exists( 'is_feature' ) ) {
            $product->is_feature = $request->input( 'is_feature' );
        }
        if ( $request->exists( 'is_show_website' ) ) {
            $product->is_show_website = $request->input( 'is_show_website' );
        }
        if ( $request->exists( 'is_stock_show' ) ) {
            $product->is_stock_show = $request->input( 'is_stock_show' );
        }
        if ( $request->exists( 'pre_order' ) ) {
            $product->pre_order = (string) $request->input( 'pre_order' );
        }

        $product->save();

        try {
            ProductPreOrderService::saveSettings( $product, [
                'pre_order'              => $request->input( 'pre_order', $product->pre_order ),
                'expected_delivery_date' => $request->input( 'expected_delivery_date' ),
                'quantity_limit'         => $request->input( 'pre_order_quantity_limit' ),
                'advance_amount'         => $request->input( 'pre_order_advance_amount' ),
                'payment_options'        => $request->input( 'pre_order_payment_options', 'both' ),
                'status'                 => $request->input( 'pre_order_status' ),
            ] );
        } catch ( ValidationException $e ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Validation error',
                'errors'  => $e->errors(),
            ], 400 );
        }

        return response()->json( [
            'status'     => 200,
            'message'    => 'Options updated',
            'product_id' => $product->id,
        ] );
    }

    /**
     * Attach tab meta (shipping / variant attribute selection) onto product for edit responses.
     */
    public static function appendTabMetaToProduct( Product $product ): void
    {
        $controller = new self();
        $meta       = $controller->getTabMeta( $product );

        $shipping = array_merge( [
            'is_physical_product' => 0,
            'package_type_id'     => null,
            'length'              => null,
            'width'               => null,
            'height'              => null,
            'dimension_unit'      => 'in',
            'weight'              => null,
            'weight_unit'         => 'lb',
        ], is_array( $meta['shipping'] ?? null ) ? $meta['shipping'] : [] );

        // Nested object for the shipping tab + flat keys for older clients.
        $product->setAttribute( 'shipping', $shipping );
        foreach ( $shipping as $key => $value ) {
            $product->setAttribute( $key, $value );
        }

        $product->setAttribute( 'has_variants', (int) ( $meta['has_variants'] ?? ( is_array( $product->variants ) && $product->variants !== [] ? 1 : 0 ) ) );
        $product->setAttribute( 'variant_attributes', array_values( (array) ( $meta['variant_attributes'] ?? [] ) ) );

        // Do not leak raw meta JSON blob in edit payload.
        $product->makeHidden( ['request'] );
    }

    private function findOwnedProduct( $id ): ?Product
    {
        return Product::query()
            ->where( 'vendor_id', vendorId() )
            ->find( $id );
    }

    private function notFound()
    {
        return response()->json( [
            'status'  => 404,
            'message' => 'Product Not Found',
        ], 404 );
    }

    private function validationError( $validator )
    {
        return response()->json( [
            'status'  => 400,
            'errors'  => $validator->messages(),
            'message' => 'Please check the required fields.',
        ], 400 );
    }

    private function mapSpecifications( array $questions, array $answers ): array
    {
        return collect( $questions )->map( function ( $item, $key ) use ( $answers ) {
            return [
                'specification'     => $item,
                'specification_ans' => $answers[$key] ?? null,
            ];
        } )->values()->toArray();
    }

    private function generateSku(): string
    {
        do {
            $sku = 'SKU-' . strtoupper( substr( uniqid(), -8 ) );
        } while ( Product::where( 'sku', $sku )->exists() );

        return $sku;
    }

    /**
     * Decode JSON-string or array request payloads (multipart often sends JSON strings).
     *
     * @param  mixed  $value
     * @return array<int, mixed>
     */
    private function decodeArrayInput( $value ): array
    {
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            $value   = is_array( $decoded ) ? $decoded : [];
        }

        return is_array( $value ) ? array_values( $value ) : [];
    }

    /**
     * @param  array<int, mixed>  $variants
     * @return array<int, array<string, mixed>>
     */
    private function normalizeVariantsPayload( array $variants ): array
    {
        return collect( $variants )->map( function ( $variant, $index ) {
            $variant = (array) $variant;
            $options = $variant['options'] ?? [];
            if ( is_string( $options ) ) {
                $decoded = json_decode( $options, true );
                $options = is_array( $decoded ) ? $decoded : [];
            }

            $id = $variant['variant_id'] ?? $variant['id'] ?? null;
            $id = is_numeric( $id ) ? (int) $id : null;

            $row = [
                'id'         => $id,
                'variant_id' => $id,
                'key'        => $variant['key'] ?? null,
                'label'      => $variant['label'] ?? null,
                'sku'        => $variant['sku'] ?? null,
                'image'      => $variant['image'] ?? null,
                'qty'        => max( 0, (int) ( $variant['qty'] ?? 0 ) ),
                'sort_order' => (int) ( $variant['sort_order'] ?? $index ),
                'options'    => array_values( (array) $options ),
                'unit_id'    => $variant['unit_id'] ?? null,
                'size_id'    => $variant['size_id'] ?? null,
                'color_id'   => $variant['color_id'] ?? null,
            ];

            // Only set price/rate when the client explicitly sent a numeric value
            // (multipart sends strings like "120" — coerce via toDecimal).
            $price = ProductVariantService::toDecimal( $variant['price'] ?? null );
            $rate  = ProductVariantService::toDecimal( $variant['rate'] ?? null );
            if ( $price !== null || $rate !== null ) {
                $amount        = $price ?? $rate;
                $row['price']  = $amount;
                $row['rate']   = $amount;
            }

            return $row;
        } )->values()->toArray();
    }

    private function getTabMeta( Product $product ): array
    {
        $raw = $product->getAttributes()['request'] ?? null;
        if ( $raw === null || $raw === '' || $raw === 0 || $raw === '0' ) {
            return [];
        }

        if ( is_array( $raw ) ) {
            return $raw;
        }

        $decoded = json_decode( (string) $raw, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }

        // Recover partially truncated JSON when possible (legacy varchar(255) column).
        $trimmed = trim( (string) $raw );
        if ( $trimmed !== '' && $trimmed[0] === '{' ) {
            $repaired = $trimmed;
            if ( ! str_ends_with( $repaired, '}' ) ) {
                $repaired .= '}';
            }
            $decoded = json_decode( $repaired, true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }

        return [];
    }

    private function putTabMeta( Product $product, array $meta ): void
    {
        // Top-level merge: price/options tabs keep shipping; shipping tab keeps variant meta.
        // (Do not use array_replace_recursive — it cannot clear arrays like variant_attributes.)
        $merged = array_merge( $this->getTabMeta( $product ), $meta );
        $product->setAttribute( 'request', json_encode( $merged, JSON_UNESCAPED_UNICODE ) );
    }
}
