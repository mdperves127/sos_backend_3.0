<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignTicketRequest;
use App\Models\SupportAssigned;
use Illuminate\Http\Request;

class AssignController extends Controller
{
    function store(AssignTicketRequest $request)
    {
        $supportAssigned =  SupportAssigned::where('support_box_id', request('support_box_id'))
            ->where('user_id', request('user_id'))
            ->first();
        if ($supportAssigned)
            return response()->json([
                'success' => false,
                'message' => 'This ticket is already assigned to this user'
            ], 400);

        SupportAssigned::create([
            'support_box_id' => request('support_box_id'),
            'user_id' => request('user_id'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ticket assigned successfully'
        ]);
    }
}
