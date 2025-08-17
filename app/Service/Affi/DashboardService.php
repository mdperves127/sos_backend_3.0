<?php

namespace App\Service\Affi;

use App\Enums\Status;
use App\Models\Order;
use App\Models\PendingBalance;
use App\Models\ProductDetails;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class DashboardService {
    static function index() {
        $pendingBalance  = new PendingBalance();
        $affiliator_id   = auth()->user()->id;
        $today           = Carbon::today();
        $product_details = new ProductDetails();
        $order           = new Order();

        $today_earning = $pendingBalance::where( [
            'affiliator_id' => $affiliator_id,
            'status'        => Status::Success->value,
        ] )->whereDate( 'created_at', $today )
            ->sum( 'amount' );

        $active_product = $product_details->where( [
            'user_id' => $affiliator_id,
            'status'  => 1,
        ] )->count();

        $requested_product = $product_details->where( [
            'user_id' => $affiliator_id,
            'status'  => 2,
        ] )->count();

        $active_orders = $order->where( [
            'affiliator_id' => $affiliator_id,
            'status'        => Status::Delivered->value,
        ] )->count();

        $total_order_amount = $order->where( [
            'affiliator_id' => Auth::id(),
            'status'        => Status::Delivered->value,
        ] )->whereDate( 'created_at', $today )->sum( 'product_amount' );

        return response()->json( [
            'today_earning'      => $today_earning,
            'active_product'     => $active_product,
            'requested_product'  => $requested_product,
            'active_orders'      => $active_orders,
            'total_order_amount' => $total_order_amount,
        ] );
    }
    static function orderVsRevenue() {
        $today_data = Order::selectRaw( 'HOUR(created_at) AS hour, COUNT(*) AS order_count, SUM(afi_amount) AS comission' )
            ->whereDate( 'created_at', Carbon::today() )
            ->where( 'affiliator_id', auth()->user()->id )
            ->whereIn( 'status', [Status::Pending->value, Status::Progress->value, Status::Delivered->value] )
            ->groupBy( 'hour' )
            ->orderBy( 'hour', 'asc' )
            ->get();

        $daily_data = [
            'label'     => $today_data->pluck( 'hour' )->toArray(),
            'order'     => $today_data->pluck( 'order_count' )->toArray(),
            'comission' => $today_data->pluck( 'comission' )->toArray(),
        ];

        $weeklyData = Order::selectRaw( 'DATE(created_at) AS date, COUNT(*) AS order_count, SUM(afi_amount) AS comission' )
            ->whereBetween( 'created_at', [Carbon::now()->subDays( 7 )->startOfDay(), Carbon::now()->endOfDay()] )
            ->where( 'affiliator_id', auth()->user()->id )
            ->whereIn( 'status', [Status::Pending->value, Status::Progress->value, Status::Delivered->value] )
            ->groupBy( 'date' )
            ->orderBy( 'date', 'asc' )
            ->get();

        $weekly_data = [
            'label'     => $weeklyData->pluck( 'date' )->map( function ( $date ) {
                return Carbon::parse( $date )->format( 'd' );
            } )->toArray(),
            'order'     => $weeklyData->pluck( 'order_count' )->toArray(),
            'comission' => $weeklyData->pluck( 'comission' )->toArray(),
        ];

        $monthlyData = Order::selectRaw( 'DATE(created_at) AS date, COUNT(*) AS order_count, SUM(afi_amount) AS comission' )
            ->whereBetween( 'created_at', [Carbon::now()->subDays( 30 )->startOfDay(), Carbon::now()->endOfDay()] )
            ->where( 'affiliator_id', auth()->user()->id )
            ->whereIn( 'status', [Status::Pending->value, Status::Progress->value, Status::Delivered->value] )
            ->groupBy( 'date' )
            ->orderBy( 'date', 'asc' )
            ->get();

        $monthly_data = [
            'label'     => $monthlyData->pluck( 'date' )->map( function ( $date ) {
                return Carbon::parse( $date )->format( 'd' );
            } )->toArray(),
            'order'     => $monthlyData->pluck( 'order_count' )->toArray(),
            'comission' => $monthlyData->pluck( 'comission' )->toArray(),
        ];

        return response()->json( [
            'daily'   => $daily_data,
            'weekly'  => $weekly_data,
            'monthly' => $monthly_data,
        ] );
    }
}
