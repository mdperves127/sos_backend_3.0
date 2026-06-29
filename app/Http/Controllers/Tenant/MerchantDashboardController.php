<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PosSales;
use App\Models\PosSalesDetails;
use App\Models\Product;
use App\Models\ProductPurchase;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MerchantDashboardController extends Controller {

    private const ACTIVE_STATUSES = ['active', 'progress', 'processing', 'received', 'ready'];

    private const PENDING_STATUSES = ['pending', 'hold'];

    private const COMPLETED_STATUSES = ['delivered', 'completed', 'received'];

    private const PROCESSING_STATUSES = ['progress', 'processing', 'ready', 'received'];

    private const REVENUE_STATUSES = ['pending', 'progress', 'processing', 'delivered', 'completed', 'received'];

    private function merchantOwnerId(): int {
        return (int) tenantOwnerId();
    }

    private function merchantOrders(): Builder {
        return Order::query()->where( 'vendor_id', $this->merchantOwnerId() );
    }

    private function merchantPosSales(): Builder {
        return PosSales::query()->where( 'vendor_id', $this->merchantOwnerId() );
    }

    private function posSaleDateExpression(): string {
        return 'DATE(COALESCE(NULLIF(sale_date, ""), created_at))';
    }

    private function scopePosSalesOnDate( Builder $query, Carbon $date ): Builder {
        return ( clone $query )->whereRaw( $this->posSaleDateExpression() . ' = ?', [$date->toDateString()] );
    }

    private function scopePosSalesBetween( Builder $query, Carbon $start, Carbon $end ): Builder {
        return ( clone $query )->whereRaw(
            $this->posSaleDateExpression() . ' BETWEEN ? AND ?',
            [$start->toDateString(), $end->toDateString()]
        );
    }

    private function countOrders( $query ): int {
        return (int) ( clone $query )->count();
    }

    private function countPosSales( $query ): int {
        return (int) ( clone $query )->count();
    }

    private function sumRevenue( $query ): float {
        return (float) ( clone $query )->sum( DB::raw( 'COALESCE(product_amount, 0) + COALESCE(delivery_charge, 0)' ) );
    }

    private function sumPosRevenue( $query ): float {
        return (float) ( clone $query )->sum( DB::raw( 'COALESCE(total_price, 0)' ) );
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

        $base    = $this->merchantOrders();
        $posBase = $this->merchantPosSales();

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
        $todayPosSales = $this->countPosSales( $this->scopePosSalesOnDate( clone $posBase, $today ) );
        $yesterdayOrders = $this->countOrders( ( clone $base )->whereDate( 'created_at', $yesterday ) );
        $yesterdayPosSales = $this->countPosSales( $this->scopePosSalesOnDate( clone $posBase, $yesterday ) );

        $todayCompletedQuery = ( clone $base )->whereIn( 'status', self::COMPLETED_STATUSES )->whereDate( 'created_at', $today );
        $todayPosRevenueQuery = $this->scopePosSalesOnDate( clone $posBase, $today );

        $todaySell = $this->sumRevenue( clone $todayCompletedQuery )
            + $this->sumPosRevenue( clone $todayPosRevenueQuery );
        $yesterdaySell = $this->sumRevenue(
            ( clone $base )->whereIn( 'status', self::COMPLETED_STATUSES )->whereDate( 'created_at', $yesterday )
        ) + $this->sumPosRevenue( $this->scopePosSalesOnDate( clone $posBase, $yesterday ) );
        $todayCompletedCount = $this->countOrders( clone $todayCompletedQuery );

        $totalOrders = $this->countOrders( clone $base );
        $totalPosSales = $this->countPosSales( clone $posBase );

        $todaySalesCount = $todayOrders + $todayPosSales;
        $yesterdaySalesCount = $yesterdayOrders + $yesterdayPosSales;

        $activeTrend  = $this->metricTrend( $activeNow, $activeLastMonth, 'orders', 'vs last month' );
        $pendingTrend = $this->metricTrend( $pendingNow, $pendingLastMonth, 'orders', 'vs last month' );
        $todayOrderTrend = $this->metricTrend( $todaySalesCount, $yesterdaySalesCount, 'sales', 'vs yesterday' );
        $todaySellTrend  = $this->metricTrend( $todaySell, $yesterdaySell, 'Tk', 'vs yesterday' );

        $summaryMetrics = [
            [
                'id'     => 'active',
                'title'  => 'Active Order',
                'value'  => $activeNow,
                'format' => 'number',
                'change' => $activeTrend['change'],
                'trend'  => $activeTrend['trend'],
                'footer' => 'Based on ' . $this->formatNumber( $totalOrders ) . ' online orders and '
                    . $this->formatNumber( $totalPosSales ) . ' POS sales',
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
                'value'  => $todaySalesCount,
                'format' => 'number',
                'change' => $todayOrderTrend['change'],
                'trend'  => $todayOrderTrend['trend'],
                'footer' => $todayOrders . ' online, ' . $todayPosSales . ' POS',
            ],
            [
                'id'     => 'today-sell',
                'title'  => 'Today Sell',
                'value'  => round( $todaySell, 2 ),
                'format' => 'currency',
                'change' => $todaySellTrend['change'],
                'trend'  => $todaySellTrend['trend'],
                'footer' => 'From ' . $todayCompletedCount . ' completed orders and ' . $todayPosSales . ' POS sales',
            ],
        ];

        $weekStart = $now->copy()->startOfWeek( Carbon::MONDAY );
        $weeklyRevenueData = [];
        $dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        for ( $i = 0; $i < 7; $i++ ) {
            $day     = $weekStart->copy()->addDays( $i );
            $dayBase = ( clone $base )->whereDate( 'created_at', $day );
            $dayPos  = $this->scopePosSalesOnDate( clone $posBase, $day );

            $weeklyRevenueData[] = [
                'day'      => $dayLabels[$i],
                'revenue'  => round(
                    $this->sumRevenue( ( clone $dayBase )->whereIn( 'status', self::REVENUE_STATUSES ) )
                    + $this->sumPosRevenue( clone $dayPos ),
                    2
                ),
                'expenses' => round( (float) ProductPurchase::query()
                    ->where( 'vendor_id', $this->merchantOwnerId() )
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
            $monthPos   = $this->scopePosSalesBetween( clone $posBase, $monthStart, $monthEnd );

            $salesPipelineOrders[] = [
                'month' => $monthStart->format( 'M' ),
                'value' => $this->countOrders( clone $monthBase ) + $this->countPosSales( clone $monthPos ),
            ];
            $salesPipelineSales[] = [
                'month' => $monthStart->format( 'M' ),
                'value' => round(
                    $this->sumRevenue( ( clone $monthBase )->whereIn( 'status', self::REVENUE_STATUSES ) )
                    + $this->sumPosRevenue( clone $monthPos ),
                    2
                ),
            ];
        }

        $completedOrders = $this->countOrders( ( clone $base )->whereIn( 'status', self::COMPLETED_STATUSES ) );
        $posSalesCount   = $this->countPosSales( clone $posBase );

        $ordersOverviewData = [
            [
                'status' => 'completed',
                'orders' => $completedOrders + $posSalesCount,
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

        $recentOnlineOrders = ( clone $base )
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
                    'sortDate'     => $order->created_at,
                    'amount'       => round( $amount, 2 ),
                    'priority'     => $this->mapOrderPriority( (float) ( $order->due_amount ?? 0 ), $amount ),
                    'status'       => $this->mapRecentOrderStatus( (string) $order->status, (float) ( $order->due_amount ?? 0 ) ),
                    'source'       => 'order',
                ];
            } );

        $recentPosSales = ( clone $posBase )
            ->with( ['customer' => fn ( $query ) => $query->select( 'id', 'customer_name' )] )
            ->latest()
            ->limit( 15 )
            ->get()
            ->map( function ( PosSales $sale ) {
                $customerName = $sale->customer?->customer_name ?? 'Walk-in Customer';
                $amount       = (float) ( $sale->total_price ?? 0 );
                $dueAmount    = (float) ( $sale->due_amount ?? 0 );
                $saleDate     = $sale->sale_date
                    ? Carbon::parse( $sale->sale_date )
                    : $sale->created_at;

                return [
                    'id'           => $sale->barcode ?: ( 'POS-' . str_pad( (string) $sale->id, 5, '0', STR_PAD_LEFT ) ),
                    'customerName' => $customerName,
                    'initials'     => $this->customerInitials( $customerName ),
                    'date'         => $saleDate?->format( 'M j, Y' ) ?? '',
                    'sortDate'     => $saleDate,
                    'amount'       => round( $amount, 2 ),
                    'priority'     => $this->mapOrderPriority( $dueAmount, $amount ),
                    'status'       => $sale->payment_status === 'paid' ? 'paid' : ( $dueAmount > 0 ? 'overdue' : 'pending' ),
                    'source'       => 'pos',
                ];
            } );

        $recentOrders = $recentOnlineOrders
            ->concat( $recentPosSales )
            ->sortByDesc( fn ( array $item ) => $item['sortDate'] ?? null )
            ->take( 15 )
            ->map( fn ( array $item ) => collect( $item )->except( 'sortDate' )->all() )
            ->values();

        $ownerId        = $this->merchantOwnerId();
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
                'pos_sold' => PosSalesDetails::query()
                    ->selectRaw( 'COALESCE(SUM(pos_sales_details.qty), 0)' )
                    ->join( 'pos_sales', 'pos_sales.id', '=', 'pos_sales_details.pos_sales_id' )
                    ->whereColumn( 'pos_sales_details.product_id', 'products.id' )
                    ->where( 'pos_sales.vendor_id', $ownerId ),
                'revenue' => Order::query()
                    ->selectRaw( 'COALESCE(SUM(COALESCE(product_amount, 0) + COALESCE(delivery_charge, 0)), 0)' )
                    ->whereColumn( 'product_id', 'products.id' )
                    ->where( 'vendor_id', $ownerId )
                    ->whereIn( 'status', $soldStatuses ),
                'pos_revenue' => PosSalesDetails::query()
                    ->selectRaw( 'COALESCE(SUM(pos_sales_details.sub_total), 0)' )
                    ->join( 'pos_sales', 'pos_sales.id', '=', 'pos_sales_details.pos_sales_id' )
                    ->whereColumn( 'pos_sales_details.product_id', 'products.id' )
                    ->where( 'pos_sales.vendor_id', $ownerId ),
            ] )
            ->orderByRaw( '(COALESCE(sold, 0) + COALESCE(pos_sold, 0)) DESC' )
            ->limit( 10 )
            ->get();

        $topSellingProducts = $topProducts->values()->map( function ( Product $product, int $index ) {
            $sold    = (int) ( $product->sold ?? 0 ) + (int) ( $product->pos_sold ?? 0 );
            $revenue = (float) ( $product->revenue ?? 0 ) + (float) ( $product->pos_revenue ?? 0 );

            return [
                'rank'    => $index + 1,
                'name'    => $product->name,
                'sold'    => $sold,
                'revenue' => round( $revenue, 2 ),
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
