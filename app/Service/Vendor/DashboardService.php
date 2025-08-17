<?php

namespace App\Service\Vendor;

use App\Enums\Status;
use App\Models\Order;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService {

    static function index() {
        $order     = new Order();
        $vendorId  = vendorId();
        $todayDate = Carbon::today();
        $product   = new Product();

        $today_sell = $order->where( [
            'vendor_id' => $vendorId,
            'status'    => Status::Delivered->value,
        ] )
            ->whereDate( 'created_at', $todayDate )
            ->sum( 'product_amount' );

        $today_order = $order->where( [
            'vendor_id' => $vendorId,
            'status'    => Status::Pending->value,
        ] )
            ->whereDate( 'created_at', $todayDate )
            ->count();

        $active_product = $product->where( [
            'user_id' => $vendorId,
            'status'  => Status::Active->value,
        ] )->count();

        $pending_order = $order->where( [
            'vendor_id' => $vendorId,
            'status'    => Status::Pending->value,
        ] )->count();

        $active_order = $order->where( [
            'vendor_id' => $vendorId,
            'status'    => Status::Active->value,
        ] )->count();

        // $recent_orders = $order->where('')

        return response()->json( [
            'today_sell'     => $today_sell,
            'today_order'    => $today_order,
            'active_product' => $active_product,
            'pending_order'  => $pending_order,
            'active_order'   => $active_order,
        ] );
    }
    static function orderVsRevenue() {
        $today_data = Order::selectRaw( 'HOUR(created_at) AS hour, COUNT(*) AS order_count, SUM(product_amount) AS sales' )
            ->whereDate( 'created_at', Carbon::today() )
            ->where( 'vendor_id', auth()->user()->id )
            ->whereIn( 'status', [Status::Pending->value, Status::Progress->value, Status::Delivered->value] )
            ->groupBy( 'hour' )
            ->orderBy( 'hour', 'asc' )
            ->get();

        $daily_data = [
            'label'   => $today_data->pluck( 'hour' )->toArray(),
            'order'   => $today_data->pluck( 'order_count' )->toArray(),
            'revenue' => $today_data->pluck( 'sales' )->toArray(),
        ];

        $weeklyData = Order::selectRaw( 'DATE(created_at) AS date, COUNT(*) AS order_count, SUM(product_amount) AS sales' )
            ->whereBetween( 'created_at', [Carbon::now()->subDays( 7 )->startOfDay(), Carbon::now()->endOfDay()] )
            ->where( 'vendor_id', auth()->user()->id )
            ->whereIn( 'status', [Status::Pending->value, Status::Progress->value, Status::Delivered->value] )
            ->groupBy( 'date' )
            ->orderBy( 'date', 'asc' )
            ->get();

        $weekly_data = [
            'label'   => $weeklyData->pluck( 'date' )->map( function ( $date ) {
                return Carbon::parse( $date )->format( 'd' );
            } )->toArray(),
            'order'   => $weeklyData->pluck( 'order_count' )->toArray(),
            'revenue' => $weeklyData->pluck( 'sales' )->toArray(),
        ];

        $monthlyData = Order::selectRaw( 'DATE(created_at) AS date, COUNT(*) AS order_count, SUM(product_amount) AS sales' )
            ->whereBetween( 'created_at', [Carbon::now()->subDays( 30 )->startOfDay(), Carbon::now()->endOfDay()] )
            ->where( 'vendor_id', auth()->user()->id )
            ->whereIn( 'status', [Status::Pending->value, Status::Progress->value, Status::Delivered->value] )
            ->groupBy( 'date' )
            ->orderBy( 'date', 'asc' )
            ->get();

        $monthly_data = [
            'label'   => $monthlyData->pluck( 'date' )->map( function ( $date ) {
                return Carbon::parse( $date )->format( 'd' );
            } )->toArray(),
            'order'   => $monthlyData->pluck( 'order_count' )->toArray(),
            'revenue' => $monthlyData->pluck( 'sales' )->toArray(),
        ];

        return response()->json( [
            'daily'   => $daily_data,
            'weekly'  => $weekly_data,
            'monthly' => $monthly_data,
        ] );
    }

    static function topten() {
        $vendorProduct = Product::select( 'id', 'name', 'image' )->where( 'user_id', auth()->user()->id )
            ->withCount( [
                'orders as product_qty' => function ( $query ) {
                    $query->select( DB::raw( 'COALESCE(SUM(qty), 0)' ) )
                        ->where( 'status', Status::Delivered->value );
                },
            ] )
            ->orderByDesc( 'product_qty' )
            ->take( 10 )
            ->get();

        return response()->json( [
            'status'  => 200,
            'message' => $vendorProduct,
        ] );
    }
}
