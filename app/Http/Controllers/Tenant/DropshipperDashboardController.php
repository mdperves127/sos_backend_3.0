<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductDetails;
use App\Services\CrossTenantQueryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class DropshipperDashboardController extends Controller {

    private const COMPLETED_STATUSES = ['delivered', 'completed'];

    private const PENDING_FULFILLMENT_STATUSES = ['pending', 'hold', 'progress', 'processing', 'received', 'ready'];

    private const REVENUE_STATUSES = ['pending', 'progress', 'processing', 'delivered', 'completed', 'received'];

    private function ownerId(): int {
        return (int) tenantOwnerId();
    }

    private function dropshipperTenantId(): string {
        return (string) tenant()->id;
    }

    private function dropshipperOrders(): Collection {
        $tenantId = $this->dropshipperTenantId();

        return CrossTenantQueryService::queryAllTenants(
            Order::class,
            fn( $query ) => $query->where( 'tenant_id', $tenantId )->orderByDesc( 'orders.created_at' )
        );
    }

    private function productDetailsQuery() {
        return ProductDetails::query()->where( 'user_id', $this->ownerId() );
    }

    private function sumRevenue( Collection $orders, ?callable $filter = null ): float {
        $items = $filter ? $orders->filter( $filter ) : $orders;

        return (float) $items->sum( fn( $order ) => (float) ( $order->product_amount ?? 0 ) + (float) ( $order->delivery_charge ?? 0 ) );
    }

    private function metricTrend( int|float $current, int|float $previous, string $unit, string $label ): array {
        $diff  = $current - $previous;
        $trend = $diff >= 0 ? 'up' : 'down';
        $sign  = $diff >= 0 ? '+' : '';

        return [
            'change' => sprintf( '%s%s %s %s', $sign, $this->formatNumber( abs( $diff ) ), $unit, $label ),
            'trend'  => $trend,
        ];
    }

    private function formatNumber( int|float $value ): string {
        if ( is_float( $value ) ) {
            return rtrim( rtrim( number_format( $value, 2, '.', ',' ), '0' ), '.' );
        }

        return number_format( $value );
    }

    private function customerInitials( string $name ): string {
        $parts    = preg_split( '/\s+/', trim( $name ) ) ?: [];
        $initials = '';
        foreach ( array_slice( $parts, 0, 2 ) as $part ) {
            $initials .= strtoupper( mb_substr( $part, 0, 1 ) );
        }

        return $initials !== '' ? $initials : 'NA';
    }

    private function mapRecentOrderStatus( string $status ): string {
        if ( in_array( $status, ['cancel', 'cancelled'], true ) ) {
            return 'cancelled';
        }
        if ( in_array( $status, self::COMPLETED_STATUSES, true ) ) {
            return 'completed';
        }
        if ( in_array( $status, ['progress', 'processing', 'received', 'ready'], true ) ) {
            return 'processing';
        }

        return 'pending';
    }

    private function expiredProductCount(): int {
        return (int) $this->productDetailsQuery()
            ->where( 'status', 1 )
            ->whereHas( 'vendor', function ( $query ) {
                $query->whereHas( 'usersubscription', function ( $query ) {
                    $query->where( function ( $query ) {
                        $query->whereHas( 'subscription', fn( $q ) => $q->where( 'plan_type', 'freemium' ) )
                            ->where( 'expire_date', '<', now() );
                    } )->orWhere( function ( $query ) {
                        $query->whereHas( 'subscription', fn( $q ) => $q->where( 'plan_type', '!=', 'freemium' ) )
                            ->where( 'expire_date', '<', now()->subMonth() );
                    } );
                } );
            } )
            ->count();
    }

    private function productNameForOrder( object $order ): string {
        $merchantTenantId = $order->tenant_id ?? null;
        if ( !$merchantTenantId || empty( $order->product_id ) ) {
            return 'Unknown Product';
        }

        $product = CrossTenantQueryService::getSingleRecordFromTenant(
            $merchantTenantId,
            Product::class,
            fn( $query ) => $query->where( 'id', $order->product_id )
        );

        return $product->name ?? 'Unknown Product';
    }

    public function statistics(): JsonResponse {
        if ( !function_exists( 'tenant' ) || tenant( 'type' ) !== 'dropshipper' ) {
            return response()->json( [
                'status'  => 403,
                'message' => 'Dashboard statistics are only available for dropshipper tenants.',
            ], 403 );
        }

        $now            = Carbon::now();
        $today          = Carbon::today();
        $yesterday      = Carbon::yesterday();
        $monthStart     = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd   = $now->copy()->subMonth()->endOfMonth();

        $productsBase   = $this->productDetailsQuery();
        $activeProducts = (int) ( clone $productsBase )->where( 'status', 1 )->count();
        $totalProducts  = (int) ( clone $productsBase )->count();
        $activeThisMonth = (int) ( clone $productsBase )->where( 'status', 1 )
            ->where( 'created_at', '>=', $monthStart )->count();

        $orders              = $this->dropshipperOrders();
        $totalOrders         = $orders->count();
        $ordersThisMonth     = $orders->filter( fn( $o ) => Carbon::parse( $o->created_at )->gte( $monthStart ) )->count();
        $ordersLastMonth     = $orders->filter( fn( $o ) => Carbon::parse( $o->created_at )->between( $lastMonthStart, $lastMonthEnd ) )->count();
        $pendingFulfillment  = $orders->filter( fn( $o ) => in_array( $o->status, self::PENDING_FULFILLMENT_STATUSES, true ) )->count();

        $ownerId   = $this->ownerId();
        $cartNow   = (int) Cart::query()->where( 'user_id', $ownerId )->whereNotNull( 'tenant_id' )->count();
        $cartYesterday = (int) Cart::query()->where( 'user_id', $ownerId )->whereNotNull( 'tenant_id' )
            ->where( 'created_at', '<=', $yesterday->copy()->endOfDay() )->count();

        $todayCompleted = $orders->filter(
            fn( $o ) => in_array( $o->status, self::COMPLETED_STATUSES, true )
                && Carbon::parse( $o->created_at )->isSameDay( $today )
        );
        $yesterdayCompleted = $orders->filter(
            fn( $o ) => in_array( $o->status, self::COMPLETED_STATUSES, true )
                && Carbon::parse( $o->created_at )->isSameDay( $yesterday )
        );

        $todayRevenue     = $this->sumRevenue( $todayCompleted );
        $yesterdayRevenue = $this->sumRevenue( $yesterdayCompleted );
        $todayCompletedCount = $todayCompleted->count();

        $summaryMetrics = [
            [
                'id'     => 'active-products',
                'title'  => 'Active Products',
                'value'  => $activeProducts,
                'format' => 'number',
                'change' => sprintf( '+%d products this month', $activeThisMonth ),
                'trend'  => $activeThisMonth > 0 ? 'up' : 'down',
                'footer' => 'From ' . $totalProducts . ' total dropshipper products',
            ],
            [
                'id'     => 'product-orders',
                'title'  => 'Product Orders',
                'value'  => $totalOrders,
                'format' => 'number',
                'change' => $this->metricTrend( $ordersThisMonth, $ordersLastMonth, 'orders', 'vs last month' )['change'],
                'trend'  => $this->metricTrend( $ordersThisMonth, $ordersLastMonth, 'orders', 'vs last month' )['trend'],
                'footer' => $pendingFulfillment . ' orders pending fulfillment',
            ],
            [
                'id'     => 'cart-items',
                'title'  => 'Cart Items',
                'value'  => $cartNow,
                'format' => 'number',
                'change' => $this->metricTrend( $cartNow, $cartYesterday, 'items', 'vs yesterday' )['change'],
                'trend'  => $this->metricTrend( $cartNow, $cartYesterday, 'items', 'vs yesterday' )['trend'],
                'footer' => 'Ready to checkout',
            ],
            [
                'id'     => 'today-revenue',
                'title'  => 'Today Revenue',
                'value'  => round( $todayRevenue, 2 ),
                'format' => 'currency',
                'change' => $this->metricTrend( $todayRevenue, $yesterdayRevenue, 'Tk', 'vs yesterday' )['change'],
                'trend'  => $this->metricTrend( $todayRevenue, $yesterdayRevenue, 'Tk', 'vs yesterday' )['trend'],
                'footer' => 'From ' . $todayCompletedCount . ' completed orders',
            ],
        ];

        $weekStart       = $now->copy()->startOfWeek( Carbon::MONDAY );
        $dayLabels       = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $weeklySalesData = [];

        for ( $i = 0; $i < 7; $i++ ) {
            $day      = $weekStart->copy()->addDays( $i );
            $dayOrders = $orders->filter( fn( $o ) => Carbon::parse( $o->created_at )->isSameDay( $day ) );

            $weeklySalesData[] = [
                'day'     => $dayLabels[$i],
                'orders'  => $dayOrders->count(),
                'revenue' => round( $this->sumRevenue(
                    $dayOrders->filter( fn( $o ) => in_array( $o->status, self::REVENUE_STATUSES, true ) )
                ), 2 ),
            ];
        }

        $ordersPipeline  = [];
        $revenuePipeline = [];
        for ( $i = 2; $i >= 0; $i-- ) {
            $monthStart = $now->copy()->subMonths( $i )->startOfMonth();
            $monthEnd   = $now->copy()->subMonths( $i )->endOfMonth();
            $monthOrders = $orders->filter(
                fn( $o ) => Carbon::parse( $o->created_at )->between( $monthStart, $monthEnd )
            );

            $ordersPipeline[] = [
                'month' => $monthStart->format( 'M' ),
                'value' => $monthOrders->count(),
            ];
            $revenuePipeline[] = [
                'month' => $monthStart->format( 'M' ),
                'value' => round( $this->sumRevenue(
                    $monthOrders->filter( fn( $o ) => in_array( $o->status, self::REVENUE_STATUSES, true ) )
                ), 2 ),
            ];
        }

        $productStatusData = [
            ['status' => 'active', 'count' => (int) ( clone $productsBase )->where( 'status', 1 )->count()],
            ['status' => 'pending', 'count' => (int) ( clone $productsBase )->where( 'status', 2 )->count()],
            ['status' => 'rejected', 'count' => (int) ( clone $productsBase )->where( 'status', 3 )->count()],
            ['status' => 'expired', 'count' => $this->expiredProductCount()],
        ];

        $recentOrders = $orders->sortByDesc( fn( $order ) => $order->created_at ?? '' )->take( 10 )->map( function ( $order ) {
            $amount = (float) ( $order->product_amount ?? 0 ) + (float) ( $order->delivery_charge ?? 0 );

            return [
                'id'           => $order->order_id ?: ( 'DSO-' . str_pad( (string) $order->id, 5, '0', STR_PAD_LEFT ) ),
                'customerName' => $order->name,
                'initials'     => $this->customerInitials( (string) $order->name ),
                'date'         => Carbon::parse( $order->created_at )->format( 'M j, Y' ),
                'amount'       => round( $amount, 2 ),
                'product'      => $this->productNameForOrder( $order ),
                'status'       => $this->mapRecentOrderStatus( (string) $order->status ),
            ];
        } )->values();

        return response()->json( [
            'status' => 200,
            'data'   => [
                'summaryMetrics'    => $summaryMetrics,
                'weeklySalesData'   => $weeklySalesData,
                'ordersPipeline'    => $ordersPipeline,
                'revenuePipeline'   => $revenuePipeline,
                'productStatusData' => $productStatusData,
                'recentOrders'      => $recentOrders,
            ],
        ] );
    }
}
