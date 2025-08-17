<?php

namespace App\Http\Controllers\API\Affiliate;

use App\Http\Controllers\Controller;
use App\Models\PendingBalance;
use Illuminate\Http\Request;

class PendingBalanceController extends Controller
{
    //
    function balance()
    {
        $balance = PendingBalance::where('affiliator_id', auth()->user()->id)
            ->latest()
            ->when(request('status') == 'pending', function ($q) {
                return $q->where('status', 'pending');
            })
            ->when(request('status') == 'success', function ($q) {
                return $q->where('status', 'success');
            })
            ->when(request('status') == 'cancel', function ($q) {
                return $q->where('status', 'cancel');
            })
            ->with('product:id,name')
            ->paginate(10);

        return response()->json([
            'status' => 200,
            'message' => $balance
        ]);
    }
}
