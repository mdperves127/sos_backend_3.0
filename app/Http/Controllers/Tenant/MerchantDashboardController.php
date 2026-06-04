<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPurchase;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MerchantDashboardController extends Controller {

    private const ACTIVE_STATUSES = ['active', 'progress', 'processing', 'received', 'ready'];

    private const PENDING_STATUSES = ['pending', 'hold'];

    private const COMPLETED_STATUSES = ['delivered', 'completed', 'received'];

    private const PROCESSING_STATUSES = ['progress', 'processing', 'ready', 'received'];

    private const REVENUE_STATUSES = ['pending', 'progress', 'processing', 'delivered', 'completed', 'received'];

    private function merchantOrders() {
        return Order::query()->where( 'vendor_id', tenantOwnerId() );
    }

    private function countOrders( $query ): int {
        return (int) ( clone $query )->count();
    }

    private function sumRevenue( $query ): float {
        return (float) ( clone $query )->sum( DB::raw( 'COALESCE(product_amount, 0) + COALESCE(delivery_charge, 0)' ) );
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

    private function mapRecentOrderStatus( string $status, float $dueAmount ): string {
        if ( in_array( $status, ['cancel', 'cancelled'], true ) ) {
            return 'draft';
        }
        if ( in_array( $status, self::COMPLETED_STATUSES, true ) ) {
            return 'paid';
        }
        if ( $dueAmount > 0 ) {
            return 'overdue';
        }

        return 'pending';
    }

    private function mapOrderPriority( float $dueAmount, float $totalAmount ): string {
        if ( $dueAmount <= 0 ) {
            return 'low';
        }
        if ( $totalAmount > 0 && $dueAmount >= ( $totalAmount * 0.5 ) ) {
            return 'high';
        }
        if ( $dueAmount >= 1000 ) {
            return 'high';
        }

        return 'medium';
    }

    private function customerInitials( string $name ): string {
        $parts = preg_split( '/\s+/', trim( $name ) ) ?: [];
        $initials = '';
        foreach ( array_slice( $parts, 0, 2 ) as $part ) {
            $initials .= strtoupper( mb_substr( $part, 0, 1 ) );
        }

        return $initials !== '' ? $initials : 'NA';
    }

    public function statistics(): JsonResponse {
        if ( !function_exists( 'tenant' ) || tenant( 'type' ) !== 'merchant' ) {
            return response()->json( [
                'status'  => 403,
                'message' => 'Dashboard statistics are only available for merchant tenants.',
            ], 403 );
        }

        $now          = Carbon::now();
        $today        = Carbon::today();
        $yesterday    = Carbon::yesterday();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd   = $now->copy()->subMonth()->endOfMonth();

        $base = $this->merchantOrders();

        $activeNow = $this->countOrders( ( clone $base )->whereIn( 'status', self::ACTIVE_STATUSES ) );
        $activeLastMonth = $this->countOrders(
            ( clone $base )->whereIn( 'status', self::ACTIVE_STATUSES )
                ->where( 'created_at', '<=', $lastMonthEnd )
        );

        $pendingNow = $this->countOrders( ( clone $base )->whereIn( 'status', self::PENDING_STATUSES ) );
        $pendingLastMonth = $this->countOrders(
            ( clone $base )->whereIn( 'status', self::PENDING_STATUSES )
                ->where( 'created_at', '<=', $lastMonthEnd )
        );

        $todayOrders = $this->countOrders( ( clone $base )->whereDate( 'created_at', $today ) );
        $yesterdayOrders = $this->countOrders( ( clone $base )->whereDate( 'created_at', $yesterday ) );

        $todayCompletedQuery = ( clone $base )->whereIn( 'status', self::COMPLETED_STATUSES )->whereDate( 'created_at', $today );

        $todaySell = $this->sumRevenue( clone $todayCompletedQuery );
        $yesterdaySell = $this->sumRevenue(
            ( clone $base )->whereIn( 'status', self::COMPLETED_STATUSES )->whereDate( 'created_at', $yesterday )
        );
        $todayCompletedCount = $this->countOrders( clone $todayCompletedQuery );

        $totalOrders = $this->countOrders( clone $base );

        $activeTrend  = $this->metricTrend( $activeNow, $activeLastMonth, 'orders', 'vs last month' );
        $pendingTrend = $this->metricTrend( $pendingNow, $pendingLastMonth, 'orders', 'vs last month' );
        $todayOrderTrend = $this->metricTrend( $todayOrders, $yesterdayOrders, 'orders', 'vs yesterday' );
        $todaySellTrend  = $this->metricTrend( $todaySell, $yesterdaySell, 'Tk', 'vs yesterday' );

        $summaryMetrics = [
            [
                'id'     => 'active',
                'title'  => 'Active Order',
                'value'  => $activeNow,
                'format' => 'number',
                'change' => $activeTrend['change'],
                'trend'  => $activeTrend['trend'],
                'footer' => 'Based on ' . $this->formatNumber( $totalOrders ) . ' total orders',
            ],
            [
                'id'     => 'pending',
                'title'  => 'Pending Order',
                'value'  => $pendingNow,
                'format' => 'number',
                'change' => $pendingTrend['change'],
                'trend'  => $pendingTrend['trend'],
                'footer' => 'Awaiting confirmation',
            ],
            [
                'id'     => 'today-order',
                'title'  => 'Today Order',
                'value'  => $todayOrders,
                'format' => 'number',
                'change' => $todayOrderTrend['change'],
                'trend'  => $todayOrderTrend['trend'],
                'footer' => 'Updated just now',
            ],
            [
                'id'     => 'today-sell',
                'title'  => 'Today Sell',
                'value'  => round( $todaySell, 2 ),
                'format' => 'currency',
                'change' => $todaySellTrend['change'],
                'trend'  => $todaySellTrend['trend'],
                'footer' => 'From ' . $todayCompletedCount . ' completed orders',
            ],
        ];

        $weekStart = $now->copy()->startOfWeek( Carbon::MONDAY );
        $weeklyRevenueData = [];
        $dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        for ( $i = 0; $i < 7; $i++ ) {
            $day     = $weekStart->copy()->addDays( $i );
            $dayBase = ( clone $base )->whereDate( 'created_at', $day );

            $weeklyRevenueData[] = [
                'day'      => $dayLabels[$i],
                'revenue'  => round( $this->sumRevenue(
                    ( clone $dayBase )->whereIn( 'status', self::REVENUE_STATUSES )
                ), 2 ),
                'expenses' => round( (float) ProductPurchase::query()
                    ->where( 'vendor_id', tenantOwnerId() )
                    ->whereDate( 'created_at', $day )
                    ->sum( DB::raw( 'COALESCE(total_price, 0)' ) ), 2 ),
            ];
        }

        $salesPipelineOrders = [];
        $salesPipelineSales  = [];
        for ( $i = 2; $i >= 0; $i-- ) {
            $monthStart = $now->copy()->subMonths( $i )->startOfMonth();
            $monthEnd   = $now->copy()->subMonths( $i )->endOfMonth();
            $monthBase  = ( clone $base )->whereBetween( 'created_at', [$monthStart, $monthEnd] );

            $salesPipelineOrders[] = [
                'month' => $monthStart->format( 'M' ),
                'value' => $this->countOrders( clone $monthBase ),
            ];
            $salesPipelineSales[] = [
                'month' => $monthStart->format( 'M' ),
                'value' => round( $this->sumRevenue(
                    ( clone $monthBase )->whereIn( 'status', self::REVENUE_STATUSES )
                ), 2 ),
            ];
        }

        $ordersOverviewData = [
            [
                'status' => 'completed',
                'orders' => $this->countOrders( ( clone $base )->whereIn( 'status', self::COMPLETED_STATUSES ) ),
            ],
            [
                'status' => 'processing',
                'orders' => $this->countOrders( ( clone $base )->whereIn( 'status', self::PROCESSING_STATUSES ) ),
            ],
            [
                'status' => 'pending',
                'orders' => $pendingNow,
            ],
            [
                'status' => 'cancelled',
                'orders' => $this->countOrders( ( clone $base )->whereIn( 'status', ['cancel', 'cancelled'] ) ),
            ],
        ];

        $recentOrders = ( clone $base )
            ->with( 'product:id,name' )
            ->latest()
            ->limit( 15 )
            ->get()
            ->map( function ( Order $order ) {
                $amount = (float) ( $order->product_amount ?? 0 ) + (float) ( $order->delivery_charge ?? 0 );

                return [
                    'id'           => $order->order_id ?: ( 'ORD-' . str_pad( (string) $order->id, 5, '0', STR_PAD_LEFT ) ),
                    'customerName' => $order->name,
                    'initials'     => $this->customerInitials( (string) $order->name ),
                    'date'         => $order->created_at?->format( 'M j, Y' ) ?? '',
                    'amount'       => round( $amount, 2 ),
                    'priority'     => $this->mapOrderPriority( (float) ( $order->due_amount ?? 0 ), $amount ),
                    'status'       => $this->mapRecentOrderStatus( (string) $order->status, (float) ( $order->due_amount ?? 0 ) ),
                ];
            } )
            ->values();

        $ownerId        = tenantOwnerId();
        $soldStatuses   = self::COMPLETED_STATUSES;
        $topProducts    = Product::query()
            ->where( 'user_id', $ownerId )
            ->select( 'id', 'name' )
            ->addSelect( [
                'sold' => Order::query()
                    ->selectRaw( 'COALESCE(SUM(qty), 0)' )
                    ->whereColumn( 'product_id', 'products.id' )
                    ->where( 'vendor_id', $ownerId )
                    ->whereIn( 'status', $soldStatuses ),
                'revenue' => Order::query()
                    ->selectRaw( 'COALESCE(SUM(COALESCE(product_amount, 0) + COALESCE(delivery_charge, 0)), 0)' )
                    ->whereColumn( 'product_id', 'products.id' )
                    ->where( 'vendor_id', $ownerId )
                    ->whereIn( 'status', $soldStatuses ),
            ] )
            ->orderByDesc( 'sold' )
            ->limit( 10 )
            ->get();

        $topSellingProducts = $topProducts->values()->map( function ( Product $product, int $index ) {
            return [
                'rank'    => $index + 1,
                'name'    => $product->name,
                'sold'    => (int) ( $product->sold ?? 0 ),
                'revenue' => round( (float) ( $product->revenue ?? 0 ), 2 ),
            ];
        } );

        return response()->json( [
            'status' => 200,
            'data'   => [
                'summaryMetrics'      => $summaryMetrics,
                'weeklyRevenueData'   => $weeklyRevenueData,
                'salesPipelineOrders' => $salesPipelineOrders,
                'salesPipelineSales'  => $salesPipelineSales,
                'ordersOverviewData'  => $ordersOverviewData,
                'recentOrders'        => $recentOrders,
                'topSellingProducts'  => $topSellingProducts,
            ],
        ] );
    }
}
