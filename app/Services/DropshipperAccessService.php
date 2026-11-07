<?php

namespace App\Services;

use App\Models\DropshipperProductAssignment;
use App\Models\ProductDetails;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant → dropshipper product access rules (private products, assignments, auto-approval).
 *
 * Assignments and products live in the merchant tenant DB; product requests
 * (product_details) live in the dropshipper tenant DB.
 */
class DropshipperAccessService {

    public const DETAIL_ACTIVE   = 1;
    public const DETAIL_PENDING  = 2;
    public const DETAIL_REJECTED = 3;

    private static array $schemaCache = [];

    public static function merchantTenant( string|int|null $tenantId ): ?Tenant {
        if ( ! $tenantId ) {
            return null;
        }

        $tenant = Tenant::on( 'mysql' )->find( $tenantId );

        return $tenant && $tenant->type === 'merchant' ? $tenant : null;
    }

    public static function merchantConnection( string|int|null $tenantId ): ?string {
        $tenant = self::merchantTenant( $tenantId );

        return $tenant ? CrossTenantQueryService::connectionForTenant( $tenant ) : null;
    }

    /**
     * Dropshipper tenant that can receive assignments / private products.
     */
    public static function isEligibleDropshipper( ?Tenant $tenant ): bool {
        if ( ! $tenant || $tenant->type !== 'dropshipper' ) {
            return false;
        }

        $status = $tenant->getAttribute( 'status' );

        return $status === null || $status === 'active';
    }

    /**
     * True once the merchant DB has the access-control columns/table (tenant migrations ran).
     */
    public static function supportsAccessControl( string $connection ): bool {
        try {
            $key = $connection . '|' . DB::connection( $connection )->getDatabaseName();
        } catch ( \Throwable $e ) {
            return false;
        }

        if ( ! array_key_exists( $key, self::$schemaCache ) ) {
            try {
                $schema = Schema::connection( $connection );
                self::$schemaCache[$key] = $schema->hasColumn( 'products', 'is_private' )
                    && $schema->hasTable( 'dropshipper_product_assignments' );
            } catch ( \Throwable $e ) {
                self::$schemaCache[$key] = false;
            }
        }

        return self::$schemaCache[$key];
    }

    public static function isPrivate( object $product ): bool {
        return (int) ( $product->is_private ?? 0 ) === 1;
    }

    public static function hasActiveAssignment( string $connection, string $dropshipperTenantId, object $product ): bool {
        if ( ! self::supportsAccessControl( $connection ) ) {
            return false;
        }

        return DB::connection( $connection )
            ->table( 'dropshipper_product_assignments' )
            ->where( 'dropshipper_tenant_id', $dropshipperTenantId )
            ->where( 'status', DropshipperProductAssignment::STATUS_ACTIVE )
            ->where( function ( $query ) use ( $product ) {
                $query->where( function ( $q ) use ( $product ) {
                    $q->where( 'assignable_type', DropshipperProductAssignment::TYPE_PRODUCT )
                        ->where( 'product_id', $product->id );
                } );

                if ( ! empty( $product->category_id ) ) {
                    $query->orWhere( function ( $q ) use ( $product ) {
                        $q->where( 'assignable_type', DropshipperProductAssignment::TYPE_CATEGORY )
                            ->where( 'category_id', $product->category_id );
                    } );
                }
            } )
            ->exists();
    }

    /**
     * Whether the dropshipper may see/use the merchant product.
     *
     * $requireMarketplace adds the marketplace eligibility rules (active + is_affiliate)
     * used for discovery and new requests; cart/checkout keep their own product checks.
     */
    public static function canAccessProduct(
        string $connection,
        ?string $dropshipperTenantId,
        object $product,
        bool $requireMarketplace = false
    ): bool {
        if ( $requireMarketplace ) {
            if ( ( $product->status ?? null ) !== 'active' || (string) ( $product->is_affiliate ?? '0' ) !== '1' ) {
                return false;
            }
        }

        if ( ! self::isPrivate( $product ) ) {
            return true;
        }

        if ( ! $dropshipperTenantId ) {
            return false;
        }

        return self::hasActiveAssignment( $connection, $dropshipperTenantId, $product );
    }

    /**
     * Load a merchant product the dropshipper may access, or null (also for unknown IDs,
     * so private products cannot be discovered by ID guessing).
     */
    public static function accessibleProduct(
        string|int $merchantTenantId,
        ?string $dropshipperTenantId,
        int|string $productId,
        bool $requireMarketplace = true
    ): ?object {
        $connection = self::merchantConnection( $merchantTenantId );
        if ( ! $connection ) {
            return null;
        }

        $product = DB::connection( $connection )->table( 'products' )->where( 'id', $productId )->first();
        if ( ! $product ) {
            return null;
        }

        return self::canAccessProduct( $connection, $dropshipperTenantId, $product, $requireMarketplace )
            ? $product
            : null;
    }

    /**
     * Auto-approval: assignment > explicit product setting > category setting > off.
     */
    public static function shouldAutoApprove( string $connection, string $dropshipperTenantId, object $product ): bool {
        if ( ! self::supportsAccessControl( $connection ) ) {
            return false;
        }

        if ( self::hasActiveAssignment( $connection, $dropshipperTenantId, $product ) ) {
            return true;
        }

        if ( isset( $product->auto_approve_dropshipper ) && $product->auto_approve_dropshipper !== null ) {
            return (int) $product->auto_approve_dropshipper === 1;
        }

        if ( empty( $product->category_id ) ) {
            return false;
        }

        if ( ! Schema::connection( $connection )->hasColumn( 'categories', 'auto_approve_dropshipper' ) ) {
            return false;
        }

        return (int) DB::connection( $connection )
            ->table( 'categories' )
            ->where( 'id', $product->category_id )
            ->value( 'auto_approve_dropshipper' ) === 1;
    }

    /**
     * Restrict a products query (merchant connection, query builder) to rows the dropshipper may see.
     */
    public static function applyMarketplaceVisibility( $query, ?string $dropshipperTenantId ) {
        $connection = $query->getConnection()->getName();

        if ( ! self::supportsAccessControl( $connection ) ) {
            return $query;
        }

        return $query->where( function ( $visible ) use ( $dropshipperTenantId ) {
            $visible->where( 'products.is_private', 0 )->orWhereNull( 'products.is_private' );

            if ( $dropshipperTenantId ) {
                $visible->orWhereExists( function ( $assigned ) use ( $dropshipperTenantId ) {
                    $assigned->select( DB::raw( 1 ) )
                        ->from( 'dropshipper_product_assignments as dpa' )
                        ->where( 'dpa.dropshipper_tenant_id', $dropshipperTenantId )
                        ->where( 'dpa.status', DropshipperProductAssignment::STATUS_ACTIVE )
                        ->where( function ( $target ) {
                            $target->where( function ( $q ) {
                                $q->where( 'dpa.assignable_type', DropshipperProductAssignment::TYPE_PRODUCT )
                                    ->whereColumn( 'dpa.product_id', 'products.id' );
                            } )->orWhere( function ( $q ) {
                                $q->where( 'dpa.assignable_type', DropshipperProductAssignment::TYPE_CATEGORY )
                                    ->whereColumn( 'dpa.category_id', 'products.category_id' );
                            } );
                        } );
                } );
            }
        } );
    }

    /**
     * Move a dropshipper's active/pending listings for these merchant products to rejected.
     * Existing orders are untouched; carts are blocked at checkout by the access re-check.
     */
    public static function revokeListings(
        string|int $merchantTenantId,
        string $dropshipperTenantId,
        array $productIds,
        string $reason = 'Access revoked by merchant'
    ): int {
        $productIds = array_values( array_unique( array_filter( array_map( 'intval', $productIds ) ) ) );
        if ( $productIds === [] ) {
            return 0;
        }

        $dropshipper = Tenant::on( 'mysql' )->find( $dropshipperTenantId );
        if ( ! $dropshipper || $dropshipper->type !== 'dropshipper' ) {
            return 0;
        }

        try {
            $connection = CrossTenantQueryService::connectionForTenant( $dropshipper );

            return ProductDetails::on( $connection )
                ->where( 'tenant_id', (string) $merchantTenantId )
                ->whereIn( 'product_id', $productIds )
                ->whereIn( 'status', [self::DETAIL_ACTIVE, self::DETAIL_PENDING] )
                ->update( ['status' => self::DETAIL_REJECTED, 'reason' => $reason, 'updated_at' => now()] );
        } catch ( \Throwable $e ) {
            Log::warning( 'dropship.revoke_listings_failed', [
                'merchant_tenant_id'    => (string) $merchantTenantId,
                'dropshipper_tenant_id' => $dropshipperTenantId,
                'message'               => $e->getMessage(),
            ] );

            return 0;
        }
    }

    /**
     * Approve pending requests that are now covered by an assignment.
     */
    public static function approvePendingRequests( string|int $merchantTenantId, string $dropshipperTenantId, array $productIds ): int {
        $productIds = array_values( array_unique( array_filter( array_map( 'intval', $productIds ) ) ) );
        if ( $productIds === [] ) {
            return 0;
        }

        $dropshipper = Tenant::on( 'mysql' )->find( $dropshipperTenantId );
        if ( ! self::isEligibleDropshipper( $dropshipper ) ) {
            return 0;
        }

        try {
            $connection = CrossTenantQueryService::connectionForTenant( $dropshipper );

            return ProductDetails::on( $connection )
                ->where( 'tenant_id', (string) $merchantTenantId )
                ->whereIn( 'product_id', $productIds )
                ->where( 'status', self::DETAIL_PENDING )
                ->update( ['status' => self::DETAIL_ACTIVE, 'updated_at' => now()] );
        } catch ( \Throwable $e ) {
            Log::warning( 'dropship.approve_pending_failed', [
                'merchant_tenant_id'    => (string) $merchantTenantId,
                'dropshipper_tenant_id' => $dropshipperTenantId,
                'message'               => $e->getMessage(),
            ] );

            return 0;
        }
    }

    /**
     * Product IDs this dropshipper may use via active assignments on one merchant DB
     * (direct product assignments + products in assigned categories).
     *
     * @return array<int, int>
     */
    public static function assignedProductIds( string $merchantConnection, string $dropshipperTenantId ): array {
        if ( ! self::supportsAccessControl( $merchantConnection ) ) {
            return [];
        }

        $assignments = DB::connection( $merchantConnection )
            ->table( 'dropshipper_product_assignments' )
            ->where( 'dropshipper_tenant_id', $dropshipperTenantId )
            ->where( 'status', DropshipperProductAssignment::STATUS_ACTIVE )
            ->get( ['assignable_type', 'product_id', 'category_id'] );

        if ( $assignments->isEmpty() ) {
            return [];
        }

        $productIds  = [];
        $categoryIds = [];

        foreach ( $assignments as $assignment ) {
            if ( $assignment->assignable_type === DropshipperProductAssignment::TYPE_PRODUCT && $assignment->product_id ) {
                $productIds[] = (int) $assignment->product_id;
            }

            if ( $assignment->assignable_type === DropshipperProductAssignment::TYPE_CATEGORY && $assignment->category_id ) {
                $categoryIds[] = (int) $assignment->category_id;
            }
        }

        if ( $categoryIds !== [] ) {
            $fromCategories = DB::connection( $merchantConnection )
                ->table( 'products' )
                ->whereIn( 'category_id', array_values( array_unique( $categoryIds ) ) )
                ->where( 'status', 'active' )
                ->where( 'is_affiliate', '1' )
                ->pluck( 'id' )
                ->map( fn ( $id ) => (int) $id )
                ->all();

            $productIds = array_merge( $productIds, $fromCategories );
        }

        $productIds = array_values( array_unique( array_filter( $productIds ) ) );
        if ( $productIds === [] ) {
            return [];
        }

        // Keep only sellable marketplace products that still exist.
        return DB::connection( $merchantConnection )
            ->table( 'products' )
            ->whereIn( 'id', $productIds )
            ->where( 'status', 'active' )
            ->where( 'is_affiliate', '1' )
            ->pluck( 'id' )
            ->map( fn ( $id ) => (int) $id )
            ->all();
    }

    /**
     * Create or activate dropshipper product_details rows for assigned merchant products.
     * Used when a merchant assigns access and when the dropshipper opens "active products".
     */
    public static function ensureListingsForProducts(
        string|int $merchantTenantId,
        string $dropshipperTenantId,
        array $productIds
    ): int {
        $productIds = array_values( array_unique( array_filter( array_map( 'intval', $productIds ) ) ) );
        if ( $productIds === [] ) {
            return 0;
        }

        $dropshipper = Tenant::on( 'mysql' )->find( $dropshipperTenantId );
        if ( ! self::isEligibleDropshipper( $dropshipper ) ) {
            return 0;
        }

        try {
            $connection = CrossTenantQueryService::connectionForTenant( $dropshipper );
            $merchantId = (string) $merchantTenantId;
            $changed    = 0;

            DB::connection( $connection )->transaction( function () use ( $connection, $merchantId, $productIds, &$changed ) {
                foreach ( $productIds as $productId ) {
                    $existing = ProductDetails::on( $connection )
                        ->where( 'tenant_id', $merchantId )
                        ->where( 'product_id', $productId )
                        ->lockForUpdate()
                        ->orderByRaw( 'FIELD(status, 1, 2, 3)' )
                        ->first();

                    if ( $existing && (int) $existing->status === self::DETAIL_ACTIVE ) {
                        continue;
                    }

                    if ( $existing ) {
                        $existing->status = self::DETAIL_ACTIVE;
                        $existing->reason = null;
                        $existing->save();
                        $changed++;
                        continue;
                    }

                    ProductDetails::on( $connection )->create( [
                        'product_id' => $productId,
                        'vendor_id'  => 0,
                        'user_id'    => 0,
                        'tenant_id'  => $merchantId,
                        'uniqid'     => uniqid(),
                        'status'     => self::DETAIL_ACTIVE,
                    ] );
                    $changed++;
                }
            } );

            return $changed;
        } catch ( \Throwable $e ) {
            Log::warning( 'dropship.ensure_listings_failed', [
                'merchant_tenant_id'    => (string) $merchantTenantId,
                'dropshipper_tenant_id' => $dropshipperTenantId,
                'message'               => $e->getMessage(),
            ] );

            return 0;
        }
    }

    /**
     * Sync every merchant assignment (product + category) into the dropshipper's active listings.
     */
    public static function ensureAssignedListings( string $dropshipperTenantId ): int {
        $dropshipper = Tenant::on( 'mysql' )->find( $dropshipperTenantId );
        if ( ! self::isEligibleDropshipper( $dropshipper ) ) {
            return 0;
        }

        $changed   = 0;
        $merchants = Tenant::on( 'mysql' )->where( 'type', 'merchant' )->get( ['id'] );

        foreach ( $merchants as $merchant ) {
            try {
                $connection = CrossTenantQueryService::connectionForTenant( $merchant );
                $productIds = self::assignedProductIds( $connection, $dropshipperTenantId );

                if ( $productIds === [] ) {
                    continue;
                }

                $changed += self::ensureListingsForProducts( $merchant->id, $dropshipperTenantId, $productIds );
            } catch ( \Throwable $e ) {
                Log::warning( 'dropship.ensure_assigned_listings_merchant_failed', [
                    'merchant_tenant_id'    => (string) $merchant->id,
                    'dropshipper_tenant_id' => $dropshipperTenantId,
                    'message'               => $e->getMessage(),
                ] );
            } finally {
                DB::setDefaultConnection( 'mysql' );
            }
        }

        return $changed;
    }

    /**
     * After products become private (or an assignment is revoked), drop listings of every
     * dropshipper that no longer has access to them.
     *
     * @param  array<int, object>  $products  merchant product rows (id, category_id, is_private)
     * @param  array<int, string>|null  $onlyDropshipperIds  limit the sweep to these dropshippers
     */
    public static function revokeInaccessibleListings(
        string|int $merchantTenantId,
        string $merchantConnection,
        array $products,
        ?array $onlyDropshipperIds = null
    ): int {
        $privateProducts = array_values( array_filter( $products, fn ( $p ) => self::isPrivate( $p ) ) );
        if ( $privateProducts === [] ) {
            return 0;
        }

        $dropshippers = Tenant::on( 'mysql' )
            ->where( 'type', 'dropshipper' )
            ->when( $onlyDropshipperIds !== null, fn ( $q ) => $q->whereIn( 'id', $onlyDropshipperIds ) )
            ->pluck( 'id' );

        $assignments = collect();
        if ( self::supportsAccessControl( $merchantConnection ) ) {
            $productIds  = array_map( fn ( $p ) => (int) $p->id, $privateProducts );
            $categoryIds = array_values( array_filter( array_map( fn ( $p ) => (int) ( $p->category_id ?? 0 ), $privateProducts ) ) );

            $assignments = DB::connection( $merchantConnection )
                ->table( 'dropshipper_product_assignments' )
                ->where( 'status', DropshipperProductAssignment::STATUS_ACTIVE )
                ->where( function ( $q ) use ( $productIds, $categoryIds ) {
                    $q->where( function ( $p ) use ( $productIds ) {
                        $p->where( 'assignable_type', DropshipperProductAssignment::TYPE_PRODUCT )
                            ->whereIn( 'product_id', $productIds );
                    } );
                    if ( $categoryIds !== [] ) {
                        $q->orWhere( function ( $c ) use ( $categoryIds ) {
                            $c->where( 'assignable_type', DropshipperProductAssignment::TYPE_CATEGORY )
                                ->whereIn( 'category_id', $categoryIds );
                        } );
                    }
                } )
                ->get()
                ->groupBy( 'dropshipper_tenant_id' );
        }

        $revoked = 0;
        foreach ( $dropshippers as $dropshipperId ) {
            $own = $assignments->get( (string) $dropshipperId, collect() );

            $lostAccess = [];
            foreach ( $privateProducts as $product ) {
                $covered = $own->contains( function ( $a ) use ( $product ) {
                    return ( $a->assignable_type === DropshipperProductAssignment::TYPE_PRODUCT && (int) $a->product_id === (int) $product->id )
                        || ( $a->assignable_type === DropshipperProductAssignment::TYPE_CATEGORY
                            && ! empty( $product->category_id )
                            && (int) $a->category_id === (int) $product->category_id );
                } );

                if ( ! $covered ) {
                    $lostAccess[] = (int) $product->id;
                }
            }

            if ( $lostAccess !== [] ) {
                $revoked += self::revokeListings( $merchantTenantId, (string) $dropshipperId, $lostAccess );
            }
        }

        return $revoked;
    }
}
