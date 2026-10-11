<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\DropshipperProductAssignment;
use App\Models\Tenant;
use App\Services\DropshipperAccessService;
use App\Services\DropshipperPricingService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Merchant-side dropshipping controls: product/category assignments, private products,
 * auto-approval and dropshipper selling-price rules.
 */
class DropshipperAssignmentController extends Controller {

    private const PRODUCT_SETTING_FIELDS = [
        'is_private',
        'auto_approve_dropshipper',
        'allow_custom_selling_price',
        'dropshipper_price_mode',
        'dropshipper_min_price',
        'dropshipper_max_price',
        'dropshipper_fixed_price',
    ];

    public function index( Request $request ) {
        if ( $denied = $this->guard( 'active_request' ) ) {
            return $denied;
        }

        $assignments = DropshipperProductAssignment::query()
            ->with( ['product:id,name,category_id,is_private', 'category:id,name'] )
            ->when( $request->filled( 'dropshipper_tenant_id' ), fn ( $q ) => $q->where( 'dropshipper_tenant_id', $request->input( 'dropshipper_tenant_id' ) ) )
            ->when( in_array( $request->input( 'status' ), [DropshipperProductAssignment::STATUS_ACTIVE, DropshipperProductAssignment::STATUS_REVOKED], true ), fn ( $q ) => $q->where( 'status', $request->input( 'status' ) ) )
            ->when( in_array( $request->input( 'type' ), [DropshipperProductAssignment::TYPE_PRODUCT, DropshipperProductAssignment::TYPE_CATEGORY], true ), fn ( $q ) => $q->where( 'assignable_type', $request->input( 'type' ) ) )
            ->latest( 'id' )
            ->paginate( 20 );

        $tenantNames = Tenant::on( 'mysql' )
            ->whereIn( 'id', $assignments->getCollection()->pluck( 'dropshipper_tenant_id' )->unique()->values() )
            ->pluck( 'company_name', 'id' );

        $assignments->getCollection()->transform( function ( $assignment ) use ( $tenantNames ) {
            $assignment->dropshipper_name = $tenantNames[$assignment->dropshipper_tenant_id] ?? null;

            return $assignment;
        } );

        return response()->json( [
            'status' => 200,
            'data'   => $assignments,
        ] );
    }

    /**
     * Look up approved dropshippers to assign (minimal public fields only).
     */
    public function dropshippers( Request $request ) {
        if ( $denied = $this->guard( 'active_request' ) ) {
            return $denied;
        }

        $search = trim( (string) $request->input( 'search', '' ) );
        if ( mb_strlen( $search ) < 2 ) {
            return response()->json( ['status' => 200, 'data' => []] );
        }

        $dropshippers = Tenant::on( 'mysql' )
            ->where( 'type', 'dropshipper' )
            ->where( function ( $q ) use ( $search ) {
                $q->where( 'id', $search )->orWhere( 'company_name', 'like', '%' . $search . '%' );
            } )
            ->limit( 20 )
            ->get()
            ->filter( fn ( $tenant ) => DropshipperAccessService::isEligibleDropshipper( $tenant ) )
            ->map( fn ( $tenant ) => [
                'id'           => $tenant->id,
                'company_name' => $tenant->company_name,
            ] )
            ->values();

        return response()->json( ['status' => 200, 'data' => $dropshippers] );
    }

    /**
     * type=product          one product
     * type=category         whole category (also covers products added to it later)
     * type=category_products every eligible product currently in the category (snapshot)
     */
    public function store( Request $request ) {
        if ( $denied = $this->guard( 'active_request' ) ) {
            return $denied;
        }

        $validator = Validator::make( $request->all(), [
            'dropshipper_tenant_id' => ['required', 'string', 'max:191'],
            'type'                  => ['required', Rule::in( ['product', 'category', 'category_products'] )],
            'product_id'            => ['required_if:type,product', 'nullable', 'integer', 'min:1'],
            'category_id'           => ['required_if:type,category,category_products', 'nullable', 'integer', 'min:1'],
        ] );

        if ( $validator->fails() ) {
            return response()->json( ['status' => 422, 'errors' => $validator->messages()], 422 );
        }

        $dropshipperId = (string) $request->input( 'dropshipper_tenant_id' );
        if ( ! DropshipperAccessService::isEligibleDropshipper( Tenant::on( 'mysql' )->find( $dropshipperId ) ) ) {
            return response()->json( ['status' => 422, 'message' => 'Dropshipper not found or not approved.'], 422 );
        }

        $connection = $this->connection();
        if ( ! DropshipperAccessService::supportsAccessControl( $connection ) ) {
            return $this->migrationPending();
        }

        $type = $request->input( 'type' );
        if ( $type === 'product' ) {
            $product = DB::connection( $connection )->table( 'products' )->where( 'id', $request->input( 'product_id' ) )->first( ['id'] );
            if ( ! $product ) {
                return response()->json( ['status' => 404, 'message' => 'Product not found.'], 404 );
            }
            $targets    = [[DropshipperProductAssignment::TYPE_PRODUCT, (int) $product->id]];
            $productIds = [(int) $product->id];
        } else {
            $categoryId = (int) $request->input( 'category_id' );
            if ( ! DB::connection( $connection )->table( 'categories' )->where( 'id', $categoryId )->exists() ) {
                return response()->json( ['status' => 404, 'message' => 'Category not found.'], 404 );
            }

            $productIds = $this->eligibleCategoryProductIds( $connection, $categoryId );

            if ( $type === 'category' ) {
                $targets = [[DropshipperProductAssignment::TYPE_CATEGORY, $categoryId]];
            } else {
                if ( $productIds === [] ) {
                    return response()->json( ['status' => 422, 'message' => 'No eligible products in this category.'], 422 );
                }
                $targets = array_map( fn ( $id ) => [DropshipperProductAssignment::TYPE_PRODUCT, $id], $productIds );
            }
        }

        $summary = ['created' => 0, 'reactivated' => 0, 'skipped' => 0];

        DB::connection( $connection )->transaction( function () use ( $targets, $dropshipperId, &$summary ) {
            foreach ( $targets as [$targetType, $targetId] ) {
                $key = DropshipperProductAssignment::targetKey( $targetType, $targetId );

                $existing = DropshipperProductAssignment::query()
                    ->where( 'dropshipper_tenant_id', $dropshipperId )
                    ->where( 'target_key', $key )
                    ->lockForUpdate()
                    ->first();

                if ( $existing && $existing->status === DropshipperProductAssignment::STATUS_ACTIVE ) {
                    $summary['skipped']++;
                    continue;
                }

                if ( $existing ) {
                    $existing->update( [
                        'status'      => DropshipperProductAssignment::STATUS_ACTIVE,
                        'assigned_by' => Auth::id(),
                        'revoked_by'  => null,
                        'revoked_at'  => null,
                    ] );
                    $summary['reactivated']++;
                    continue;
                }

                try {
                    DropshipperProductAssignment::create( [
                        'dropshipper_tenant_id' => $dropshipperId,
                        'assignable_type'       => $targetType,
                        'product_id'            => $targetType === DropshipperProductAssignment::TYPE_PRODUCT ? $targetId : null,
                        'category_id'           => $targetType === DropshipperProductAssignment::TYPE_CATEGORY ? $targetId : null,
                        'target_key'            => $key,
                        'status'                => DropshipperProductAssignment::STATUS_ACTIVE,
                        'assigned_by'           => Auth::id(),
                    ] );
                    $summary['created']++;
                } catch ( QueryException $e ) {
                    if ( (int) ( $e->errorInfo[1] ?? 0 ) !== 1062 ) {
                        throw $e;
                    }
                    $summary['skipped']++;
                }
            }
        } );

        // Assigned access is immediate: approve requests already waiting for these products.
        $summary['approved_pending_requests'] = DropshipperAccessService::approvePendingRequests( tenant()->id, $dropshipperId, $productIds );

        return response()->json( [
            'status'  => 200,
            'message' => $summary['created'] + $summary['reactivated'] > 0 ? 'Assigned successfully.' : 'Already assigned.',
            'summary' => $summary,
        ] );
    }

    public function revoke( $id ) {
        if ( $denied = $this->guard( 'reject_request' ) ) {
            return $denied;
        }

        $connection = $this->connection();
        if ( ! DropshipperAccessService::supportsAccessControl( $connection ) ) {
            return $this->migrationPending();
        }

        $assignment = DropshipperProductAssignment::query()->find( $id );
        if ( ! $assignment ) {
            return response()->json( ['status' => 404, 'message' => 'Assignment not found.'], 404 );
        }

        if ( $assignment->status === DropshipperProductAssignment::STATUS_REVOKED ) {
            return response()->json( ['status' => 200, 'message' => 'Assignment already revoked.'] );
        }

        $assignment->update( [
            'status'     => DropshipperProductAssignment::STATUS_REVOKED,
            'revoked_by' => Auth::id(),
            'revoked_at' => now(),
        ] );

        $products = DB::connection( $connection )->table( 'products' )
            ->when(
                $assignment->assignable_type === DropshipperProductAssignment::TYPE_PRODUCT,
                fn ( $q ) => $q->where( 'id', $assignment->product_id ),
                fn ( $q ) => $q->where( 'category_id', $assignment->category_id )
            )
            ->get( ['id', 'category_id', 'is_private'] )
            ->all();

        // Only private products lose existing listings; public ones stay available to everyone.
        $revokedListings = DropshipperAccessService::revokeInaccessibleListings(
            tenant()->id,
            $connection,
            $products,
            [$assignment->dropshipper_tenant_id]
        );

        return response()->json( [
            'status'           => 200,
            'message'          => 'Assignment revoked.',
            'revoked_listings' => $revokedListings,
        ] );
    }

    public function productSettings( $productId ) {
        if ( $denied = $this->guard( 'active_request' ) ) {
            return $denied;
        }

        $connection = $this->connection();
        $product    = DB::connection( $connection )->table( 'products' )->where( 'id', $productId )->first();
        if ( ! $product ) {
            return response()->json( ['status' => 404, 'message' => 'Product not found.'], 404 );
        }

        return response()->json( [
            'status' => 200,
            'data'   => $this->productSettingsPayload( $connection, $product ),
        ] );
    }

    public function updateProductSettings( Request $request, $productId ) {
        if ( $denied = $this->guard( 'active_request' ) ) {
            return $denied;
        }

        $connection = $this->connection();
        if ( ! DropshipperAccessService::supportsAccessControl( $connection ) ) {
            return $this->migrationPending();
        }

        $product = DB::connection( $connection )->table( 'products' )->where( 'id', $productId )->first();
        if ( ! $product ) {
            return response()->json( ['status' => 404, 'message' => 'Product not found.'], 404 );
        }

        $money     = ['nullable', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'numeric', 'gt:0'];
        $validator = Validator::make( $request->all(), [
            'is_private'                 => ['sometimes', 'boolean'],
            'auto_approve_dropshipper'   => ['sometimes', 'nullable', function ( $attribute, $value, $fail ) {
                if ( ! in_array( $value, [true, false, 0, 1, '0', '1', 'true', 'false', 'inherit'], true ) ) {
                    $fail( 'auto_approve_dropshipper must be true, false or "inherit".' );
                }
            }],
            'allow_custom_selling_price' => ['sometimes', 'boolean'],
            'dropshipper_price_mode'     => ['sometimes', Rule::in( [DropshipperPricingService::MODE_RANGE, DropshipperPricingService::MODE_FIXED] )],
            'dropshipper_min_price'      => array_merge( ['sometimes'], $money ),
            'dropshipper_max_price'      => array_merge( ['sometimes'], $money ),
            'dropshipper_fixed_price'    => array_merge( ['sometimes'], $money ),
        ] );

        if ( $validator->fails() ) {
            return response()->json( ['status' => 422, 'errors' => $validator->messages()], 422 );
        }

        $changes = [];
        foreach ( self::PRODUCT_SETTING_FIELDS as $field ) {
            if ( ! $request->exists( $field ) ) {
                continue;
            }
            $value = $request->input( $field );

            $changes[$field] = match ( $field ) {
                'is_private', 'allow_custom_selling_price' => filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0,
                'auto_approve_dropshipper'                 => ( $value === null || $value === '' || $value === 'inherit' ) ? null : ( filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0 ),
                'dropshipper_price_mode'                   => $value,
                default                                    => ( $value === null || $value === '' ) ? null : round( (float) $value, 2 ),
            };
        }

        $merged = (object) array_merge( (array) $product, $changes );
        if ( $error = $this->priceRuleError( $merged ) ) {
            return response()->json( ['status' => 422, 'message' => $error], 422 );
        }

        if ( $changes !== [] ) {
            DB::connection( $connection )->table( 'products' )->where( 'id', $product->id )->update( $changes + ['updated_at' => now()] );
        }

        $revokedListings = 0;
        if ( (int) ( $product->is_private ?? 0 ) === 0 && (int) ( $merged->is_private ?? 0 ) === 1 ) {
            // Became private: dropshippers without an assignment lose their listing.
            $revokedListings = DropshipperAccessService::revokeInaccessibleListings( tenant()->id, $connection, [$merged] );
        }

        $fresh = DB::connection( $connection )->table( 'products' )->where( 'id', $product->id )->first();

        return response()->json( [
            'status'           => 200,
            'message'          => 'Dropshipping settings updated.',
            'revoked_listings' => $revokedListings,
            'data'             => $this->productSettingsPayload( $connection, $fresh ),
        ] );
    }

    public function updateCategorySettings( Request $request, $categoryId ) {
        if ( $denied = $this->guard( 'active_request' ) ) {
            return $denied;
        }

        $validator = Validator::make( $request->all(), [
            'auto_approve_dropshipper' => ['required', 'boolean'],
        ] );

        if ( $validator->fails() ) {
            return response()->json( ['status' => 422, 'errors' => $validator->messages()], 422 );
        }

        $connection = $this->connection();
        if ( ! DB::connection( $connection )->getSchemaBuilder()->hasColumn( 'categories', 'auto_approve_dropshipper' ) ) {
            return $this->migrationPending();
        }

        $updated = DB::connection( $connection )->table( 'categories' )
            ->where( 'id', $categoryId )
            ->update( [
                'auto_approve_dropshipper' => $request->boolean( 'auto_approve_dropshipper' ) ? 1 : 0,
                'updated_at'               => now(),
            ] );

        if ( ! $updated && ! DB::connection( $connection )->table( 'categories' )->where( 'id', $categoryId )->exists() ) {
            return response()->json( ['status' => 404, 'message' => 'Category not found.'], 404 );
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'Category dropshipping settings updated.',
            'data'    => [
                'category_id'              => (int) $categoryId,
                'auto_approve_dropshipper' => $request->boolean( 'auto_approve_dropshipper' ),
            ],
        ] );
    }

    private function productSettingsPayload( string $connection, object $product ): array {
        $categoryAutoApprove = null;
        if ( ! empty( $product->category_id ) && DB::connection( $connection )->getSchemaBuilder()->hasColumn( 'categories', 'auto_approve_dropshipper' ) ) {
            $categoryAutoApprove = (bool) DB::connection( $connection )->table( 'categories' )->where( 'id', $product->category_id )->value( 'auto_approve_dropshipper' );
        }

        $explicit = isset( $product->auto_approve_dropshipper ) ? (bool) $product->auto_approve_dropshipper : null;

        return [
            'product_id'                         => (int) $product->id,
            'is_private'                         => (bool) ( $product->is_private ?? false ),
            'auto_approve_dropshipper'           => $explicit,
            'category_auto_approve_dropshipper'  => $categoryAutoApprove,
            'effective_auto_approve_dropshipper' => $explicit ?? (bool) $categoryAutoApprove,
            'pricing'                            => DropshipperPricingService::publicRules( $product ),
        ];
    }

    private function priceRuleError( object $product ): ?string {
        $base  = DropshipperPricingService::basePrice( $product );
        $min   = $product->dropshipper_min_price ?? null;
        $max   = $product->dropshipper_max_price ?? null;
        $fixed = $product->dropshipper_fixed_price ?? null;

        if ( ( $product->dropshipper_price_mode ?? DropshipperPricingService::MODE_RANGE ) === DropshipperPricingService::MODE_FIXED && $fixed === null ) {
            return 'A fixed selling price is required in fixed price mode.';
        }

        foreach ( ['minimum' => $min, 'maximum' => $max, 'fixed' => $fixed] as $label => $value ) {
            if ( $value !== null && (float) $value < $base ) {
                return 'The ' . $label . ' dropshipper price cannot be lower than the product price (' . number_format( $base, 2, '.', '' ) . ').';
            }
        }

        if ( $min !== null && $max !== null && (float) $max < (float) $min ) {
            return 'The maximum dropshipper price must be greater than or equal to the minimum.';
        }

        return null;
    }

    /**
     * @return array<int, int>
     */
    private function eligibleCategoryProductIds( string $connection, int $categoryId ): array {
        return DB::connection( $connection )->table( 'products' )
            ->where( 'category_id', $categoryId )
            ->where( 'status', 'active' )
            ->where( 'is_affiliate', '1' )
            ->pluck( 'id' )
            ->map( fn ( $id ) => (int) $id )
            ->all();
    }

    private function connection(): string {
        return ( new DropshipperProductAssignment() )->getConnectionName();
    }

    private function guard( string $permission ) {
        if ( ! tenant() || tenant()->type !== 'merchant' ) {
            return response()->json( ['status' => 403, 'message' => 'Only merchants can manage dropshipper access.'], 403 );
        }

        if ( Auth::user()->role_type === 'employee' && ! tenantPermission( $permission ) ) {
            return $this->employeeMessage();
        }

        return null;
    }

    private function migrationPending() {
        return response()->json( [
            'status'  => 503,
            'message' => 'Dropshipping access controls are not available yet. Please run tenant migrations.',
        ], 503 );
    }
}
