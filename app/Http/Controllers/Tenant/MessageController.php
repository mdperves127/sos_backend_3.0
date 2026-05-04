<?php

namespace App\Http\Controllers\Tenant;

use App\Events\TenantChatMessageSent;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Concerns\ResolvesTenantChatAccess;
use App\Models\ChatReport;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class MessageController extends Controller {
    use ResolvesTenantChatAccess;

    public function getMessages( int|string $peerId ) {
        $peerId = (int) $peerId;
        $me     = (int) Auth::id();

        $tid = (string) tenant()->id;

        $messages = Message::on( 'tenant' )
            ->where( 'tenant_id', $tid )
            ->where( function ( $query ) use ( $peerId, $me ) {
                $query->where( function ( $q ) use ( $peerId, $me ) {
                    $q->where( 'sender_id', $me )->where( 'receiver_id', $peerId );
                } )->orWhere( function ( $q ) use ( $peerId, $me ) {
                    $q->where( 'sender_id', $peerId )->where( 'receiver_id', $me );
                } );
            } )
            ->orderBy( 'created_at' )
            ->get();

        return response()->json( ['success' => true, 'messages' => $messages] );
    }

    public function sendMessage( Request $request ) {
        $validator = Validator::make( $request->all(), [
            'message'     => 'required',
            'receiver_id' => 'required|integer|exists:users,id',
        ], [
            'receiver_id.exists' => 'Oops! This user not eligible to access this feature.',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'error'  => $validator->errors(),
            ] );
        }

        $sub = $this->tenantChatSubscription();
        if ( !$sub ) {
            return $this->chatAccessDeniedResponse();
        }
        if ( !$this->tenantHasChatAccess( $sub ) ) {
            return $this->chatPlanDeniedResponse();
        }

        $receiverId = (int) $request->receiver_id;
        $senderId   = (int) Auth::id();

        if ( !$this->tenantUsersAreChatPartners( $senderId, $receiverId ) ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! This user not eligible to access this feature.',
            ], 401 );
        }

        $receiver = User::on( 'tenant' )->find( $receiverId );
        if ( !$receiver ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! This user not eligible to access this feature.',
            ], 401 );
        }

        $message              = new Message();
        $message->setConnection( 'tenant' );
        $message->tenant_id   = (string) tenant()->id;
        $message->sender_id   = $senderId;
        $message->receiver_id = $receiverId;
        $message->user_id     = $senderId;
        $message->message     = $request->message;
        $message->conversation_id = 0;
        $message->save();

        event( new TenantChatMessageSent(
            (string) tenant()->id,
            $senderId,
            $receiverId,
            $message->fresh()->toArray(),
        ) );

        return response()->json( ['status' => 200] );
    }

    public function chatReport( int|string $id ) {
        $tid  = (string) tenant()->id;
        $chat = Message::on( 'tenant' )->where( 'tenant_id', $tid )->find( $id );
        if ( !$chat ) {
            return response()->json( ['status' => 404, 'message' => 'Message not found'], 404 );
        }

        $data                   = new ChatReport();
        $data->setConnection( 'tenant' );
        $data->user_id          = Auth::id();
        $data->message_id       = (int) $id;
        $data->reported_user_id = $chat->sender_id;
        $data->reason           = $chat->message;
        $data->save();

        return response()->json( [
            'status'  => 200,
            'message' => 'Chat report submitted successfully. we will take action after !',
        ] );
    }
}
