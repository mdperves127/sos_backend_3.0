<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function index()
    {
        $user = vendorId();
        $messages = Chat::with('sender', 'recipient')->get();

        return response()->json([
            'status' => 200,
            'messages' => $messages
        ]);
    }

    public function store(Request $request)
    {
        $message = new Chat();
        $message->sender_id = vendorId();
        $message->recipient_id = $request->recipient_id;
        $message->content = $request->content;
        $message->save();

        return response()->json([
            'status' => 200,
            'message' => 'Message send !'
        ]);
    }
}
