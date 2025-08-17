<?php

namespace App\Service\Admin;

use App\Enums\Status;
use App\Models\Category;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardService
{

    static  function index()
    {
        $user = new User();
        $order = new Order();
        $currentMonth = Carbon::now()->month;



        $today_revenue = $order::whereDate('created_at', Carbon::now()->toDateString())
            ->where(function ($query) {
                $query->where('status', Status::Pending->value)
                    ->orWhere('status', Status::Progress->value);
            })
            ->sum('product_amount');

        $today_order = $order::whereDate('created_at', Carbon::now()->toDateString())
            ->where(function ($query) {
                $query->where('status', Status::Pending->value)
                    ->orWhere('status', Status::Progress->value);
            })
            ->count();

        $active_vendor = $user::where([
            'role_as' => 2,
            'status' => 'active'
        ])->count();

        $active_afi = $user::where([
            'role_as' => 3,
            'status' => 'active'
        ])->count();

        $user_activity = $user::where('last_seen', '>=', Carbon::now()->subMinutes(3))
            ->where('role_as', 2)
            ->count();

        $affi_activity = $user::where('last_seen', '>=', Carbon::now()->subMinutes(3))
            ->where('role_as', 3)
            ->count();


        $monthly_completed_order = $order::whereMonth('created_at', $currentMonth)
            ->where('status', Status::Delivered->value)
            ->count();

        $monthly_cancelled_order  =  $order::whereMonth('created_at', $currentMonth)
            ->where('status', Status::Cancel->value)
            ->count();

        $monthly_progress_order  =  $order::whereMonth('created_at', $currentMonth)
            ->where('status', Status::Progress->value)
            ->count();

        $monthly_pending_order  =  $order::whereMonth('created_at', $currentMonth)
            ->where('status', Status::Pending->value)
            ->count();



        return response()->json([
            'today_revenue' => $today_revenue,
            'today_order' => $today_order,
            'active_vendor' => $active_vendor,
            'active_afi' => $active_afi,
            'vendor_activity' => $user_activity,
            'affi_activity' => $affi_activity,
            'monthly_completed_order' => $monthly_completed_order,
            'monthly_cancelled_order' => $monthly_cancelled_order,
            'monthly_progress_order' => $monthly_progress_order,
            'monthly_pending_order' => $monthly_pending_order,
        ]);
    }

    static function orderVsRevenue()
    {
        $today_data = Order::selectRaw('HOUR(created_at) AS hour, COUNT(*) AS order_count, SUM(product_amount) AS sales')
            ->whereDate('created_at', Carbon::today())
            ->whereIn('status', [Status::Pending->value, Status::Progress->value, Status::Delivered->value])
            ->groupBy('hour')
            ->orderBy('hour', 'asc')
            ->get();

        $daily_data = [
            'label' => $today_data->pluck('hour')->toArray(),
            'order' => $today_data->pluck('order_count')->toArray(),
            'revenue' => $today_data->pluck('sales')->toArray(),
        ];



        $weeklyData = Order::selectRaw('DATE(created_at) AS date, COUNT(*) AS order_count, SUM(product_amount) AS sales')
            ->whereBetween('created_at', [Carbon::now()->subDays(7)->startOfDay(), Carbon::now()->endOfDay()])
            ->whereIn('status', [Status::Pending->value, Status::Progress->value, Status::Delivered->value])
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        $weekly_data = [
            'label' => $weeklyData->pluck('date')->map(function ($date) {
                return Carbon::parse($date)->format('d');
            })->toArray(),
            'order' => $weeklyData->pluck('order_count')->toArray(),
            'revenue' => $weeklyData->pluck('sales')->toArray(),
        ];



        $monthlyData = Order::selectRaw('DATE(created_at) AS date, COUNT(*) AS order_count, SUM(product_amount) AS sales')
            ->whereBetween('created_at', [Carbon::now()->subDays(30)->startOfDay(), Carbon::now()->endOfDay()])
            ->whereIn('status', [Status::Pending->value, Status::Progress->value, Status::Delivered->value])
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        $monthly_data = [
            'label' => $monthlyData->pluck('date')->map(function ($date) {
                return Carbon::parse($date)->format('d');
            })->toArray(),
            'order' => $monthlyData->pluck('order_count')->toArray(),
            'revenue' => $monthlyData->pluck('sales')->toArray(),
        ];


        return response()->json([
            'daily' => $daily_data,
            'weekly' => $weekly_data,
            'monthly' => $monthly_data
        ]);
    }

    static  function recentOrder()
    {
        $latestOrders = Order::latest()->with(['vendor:id,name', 'affiliator:id,name', 'product:id,name,image'])->take(10)->get();
        return response()->json([
            'staus' => 200,
            'message' => $latestOrders
        ]);
    }

    static function categoryStatus()
    {
        $lastMonthStart = Carbon::now()->subMonth()->startOfMonth();
        $lastMonthEnd = Carbon::now()->subMonth()->endOfMonth();
        $currentMonthStart = Carbon::now()->startOfMonth();
        $currentMonthEnd = Carbon::now()->endOfMonth();

        $categories =   Category::query()
            ->withCount([
                'products as product_qty' => function ($query) {
                    $query->where('status', 'active');
                },
                'order as total_qty_last_month' => function ($query) use ($lastMonthStart, $lastMonthEnd) {
                    $query->select(DB::raw('sum(qty)'))
                        ->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])
                        ->where('status', 'delivered');
                },
                'order as total_qty_current_month' => function ($query) use ($currentMonthStart, $currentMonthEnd) {
                    $query->select(DB::raw('sum(qty)'))
                        ->whereBetween('created_at', [$currentMonthStart, $currentMonthEnd])
                        ->where('status', 'delivered');
                },
                'order as sold_qty' => function ($query) {
                    $query->select(DB::raw('sum(qty)'))
                        ->where('status', 'delivered');
                }
            ])
            ->orderByDesc('sold_qty')
            ->take(10)
            ->get();


        $categories = $categories->map(function ($category) {
            $category->is_up = $category->total_qty_last_month < $category->total_qty_current_month;

            $total_qty = $category->sold_qty + $category->product_qty;
            if ($total_qty != 0) {
                $category->sale_percentage = ($category->sold_qty / $total_qty) * 100;
            } else {
                $category->sale_percentage = 0; // or any other value you prefer when division by zero occurs
            }

            return $category;
        });


        return $categories;
    }
}
