<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ChatReport;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class MessageController extends Controller {
    public function getMessages( $vendorId ) {
        $messages = Message::where( function ( $query ) use ( $vendorId ) {
            $query->where( 'sender_id', Auth::id() )->where( 'receiver_id', $vendorId );
        } )->orWhere( function ( $query ) use ( $vendorId ) {
            $query->where( 'sender_id', $vendorId )->where( 'receiver_id', Auth::id() );
        } )->orderBy( 'created_at' )->get();

        return response()->json( ['success' => true, 'messages' => $messages] );
    }

    public function sendMessage( Request $request ) {
        // //Sender

        $validator = Validator::make( $request->all(), [
            'message'     => 'required',
            'receiver_id' => 'required|exists:user_subscriptions,user_id',
        ], [
            'receiver_id.exists' => 'Oops! This user not eligible to access this feature.',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'error'  => $validator->errors(),
            ] );
        }

        $user = User::find( vendorId() );
        if ( !$user->usersubscription || isactivemembership( $user->id ) == null ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! It seems you are not eligible to access this feature. Please contact the administrator for assistance.',
            ] );
        }

        if ( $user?->usersubscription?->chat_access == null ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! This service is not available with your current subscription. Please contact the administrator for assistance.',
            ] );
        }

        // //Receiver
        $receiver = user::where( 'id', $request->receiver_id )->first();

        if ( !$receiver->usersubscription || isactivemembership( $receiver->id ) == null || $receiver?->usersubscription?->chat_access == null ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! This user not eligible to access this feature.',
            ] );
        }

        $message              = new Message();
        $message->sender_id   = vendorId();
        $message->receiver_id = $request->receiver_id;
        $message->message     = $request->message;
        $message->save();

        // You might also emit an event or send a notification to the other user

        return response()->json( ['status' => 200] );
    }

    public function chatReport( $id ) {
        $chat = Message::find( $id );

        $data                   = new ChatReport();
        $data->user_id          = Auth::id();
        $data->message_id       = $id;
        $data->reported_user_id = $chat->sender_id;
        $data->reason           = $chat->message;
        $data->save();

        return response()->json( [
            "status"  => 200,
            "message" => 'Chat report submitted successfully. we will take action after !',
        ] );
    }
}
