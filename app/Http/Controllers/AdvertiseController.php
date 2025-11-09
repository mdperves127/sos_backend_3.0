<?php

namespace App\Http\Controllers;

use App\Models\AdminAdvertise;
use Illuminate\Http\Request;

class AdvertiseController extends Controller
{
    function index()
    {
        try {
            // Get authenticated user safely
            $user = auth()->user();
            if (!$user || !isset($user->id)) {
                return response()->json([
                    'status' => 401,
                    'message' => 'Unauthenticated',
                    'data' => null
                ], 401);
            }

            $userId = $user->id;

            // Ensure database connection is set
            \DB::setDefaultConnection('mysql');

            // Simple query without on() to avoid connection issues
            $data = AdminAdvertise::where('user_id', $userId)
                ->where('is_paid', 1)
                ->latest()
                ->when(request('order_id'), function ($q, $orderid) {
                    return $q->where('trxid', 'like', "%{$orderid}%");
                })
                ->select('id', 'campaign_name', 'campaign_objective', 'budget_amount', 'start_date', 'end_date', 'is_paid', 'created_at', 'status','unique_id')
                ->paginate(10);

            return response()->json([
                'status' => 200,
                'data' => $data,
                'message' => 'success'
            ]);
        } catch (\Throwable $e) {
            \Log::error('AdvertiseController@index: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine() . ' | Trace: ' . $e->getTraceAsString());
            return response()->json([
                'status' => 500,
                'message' => 'Server error occurred: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    function show($id)
    {
        try {
            // Get authenticated user safely
            $user = auth()->user();
            if (!$user || !isset($user->id)) {
                return response()->json([
                    'status' => 401,
                    'message' => 'Unauthenticated',
                    'data' => null
                ], 401);
            }

            $userId = $user->id;

            // Ensure database connection is set
            \DB::setDefaultConnection('mysql');

            $data = AdminAdvertise::where('user_id', $userId)
                ->where('is_paid', 1)
                ->with('AdvertiseAudienceFile', 'advertiseLocationFiles', 'files')
                ->find($id);

            if (!$data) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Advertisement not found',
                    'data' => null
                ], 404);
            }

            return response()->json([
                'status' => 200,
                'data' => $data,
                'message' => 'success'
            ]);
        } catch (\Throwable $e) {
            \Log::error('AdvertiseController@show: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine() . ' | Trace: ' . $e->getTraceAsString());
            return response()->json([
                'status' => 500,
                'message' => 'Server error occurred: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }


    public function advertiseCount() {
        try {
            // Get authenticated user safely
            $user = auth()->user();
            if (!$user || !isset($user->id)) {
                return response()->json([
                    'status' => 401,
                    'message' => 'Unauthenticated',
                    'data' => null
                ], 401);
            }

            $userId = $user->id;

            // Ensure database connection is set
            \DB::setDefaultConnection('mysql');

            $all  = AdminAdvertise::where('user_id', $userId)->count();
            $pending  = AdminAdvertise::where('user_id', $userId)->where('is_paid',1)->where('status', 'pending')->count();
            $progress  = AdminAdvertise::where('user_id', $userId)->where('status', 'progress')->count();
            $delivered  = AdminAdvertise::where('user_id', $userId)->where('status', 'delivered')->count();
            $cancel  = AdminAdvertise::where('user_id', $userId)->where('status', 'cancel')->count();

            return response()->json([
                'status' => 200,
                'pending' => $pending,
                'progress' => $progress,
                'delivered' => $delivered,
                'cancel' => $cancel,
                'all' => $all,
            ]);
        } catch (\Throwable $e) {
            \Log::error('AdvertiseController@advertiseCount: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine() . ' | Trace: ' . $e->getTraceAsString());
            return response()->json([
                'status' => 500,
                'message' => 'Server error occurred: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

}
