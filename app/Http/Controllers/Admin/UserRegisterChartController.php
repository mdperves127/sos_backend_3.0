<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class UserRegisterChartController extends Controller
{
    function index()
    {
        // $startDate =   request('start') ? Carbon::parse(request('start')) : Carbon::now()->subDays(30);
        // $endDate =   request('end') ? Carbon::parse(request('end')) : Carbon::now();

        // $registration_vendor = User::where('role_as', 2)
        // ->whereBetween('created_at', [$startDate, $endDate])
        //     ->selectRaw('DATE(created_at) as date, COUNT(*) as registrations')
        //     ->groupBy('date')
        //     ->orderBy('date')
        //     ->get();
        // $registration_affiliator = User::where('role_as', 3)
        // ->whereBetween('created_at', [$startDate, $endDate])
        //     ->selectRaw('DATE(created_at) as date, COUNT(*) as registrations')
        //     ->groupBy('date')
        //     ->orderBy('date')
        //     ->get();
        // $registration_user = User::where('role_as', 4)
        // ->whereBetween('created_at', [$startDate, $endDate])
        //     ->selectRaw('DATE(created_at) as date, COUNT(*) as registrations')
        //     ->groupBy('date')
        //     ->orderBy('date')
        //     ->get();

        // return response()->json([
        //     'registration_vendor' => $registration_vendor,
        //     'registration_affiliator' => $registration_affiliator,
        //     'registration_user' => $registration_user
        // ]);


        $startDate = request('start') ? Carbon::parse(request('start')) : Carbon::now()->subDays(30);
        $endDate = request('end') ? Carbon::parse(request('end')) : Carbon::now();

        // Generate a sequence of dates within the specified range
        $dateRange = collect(\Carbon\CarbonPeriod::create($startDate, $endDate))->map(function ($date) {
            return $date->format('Y-m-d');
        });
        //Vendor Data start
        $registration_vendor = User::where('role_as', 2)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as registrations')
            ->groupBy('date');

        $registration_vendor = $dateRange->map(function ($date) use ($registration_vendor) {
            return $registration_vendor->clone()
                ->whereRaw('DATE(created_at) = ?', $date)
                ->firstOr(function () use ($date) {
                    return (object)['date' => $date, 'registrations' => 0];
                });
        });
        //Vendor Data ends
        //Affiliator Data start
        $registration_affiliator = User::where('role_as', 3)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as registrations')
            ->groupBy('date');

        $registration_affiliator = $dateRange->map(function ($date) use ($registration_affiliator) {
            return $registration_affiliator->clone()
                ->whereRaw('DATE(created_at) = ?', $date)
                ->firstOr(function () use ($date) {
                    return (object)['date' => $date, 'registrations' => 0];
                });
        });
        //Affiliator Data ends
        //User Data start
        $registration_user = User::where('role_as', 4)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as registrations')
            ->groupBy('date');

        $registration_user = $dateRange->map(function ($date) use ($registration_user) {
            return $registration_user->clone()
                ->whereRaw('DATE(created_at) = ?', $date)
                ->firstOr(function () use ($date) {
                    return (object)['date' => $date, 'registrations' => 0];
                });
        });
        //User Data ends

        $registration_vendor = $registration_vendor->sortBy('date');
        $registration_affiliator = $registration_affiliator->sortBy('date');
        $registration_user = $registration_user->sortBy('date');
        return response()->json([
            'registration_vendor' => $registration_vendor,
            'registration_affiliator' => $registration_affiliator,
            'registration_user' => $registration_user,
        ]);
    }
}
