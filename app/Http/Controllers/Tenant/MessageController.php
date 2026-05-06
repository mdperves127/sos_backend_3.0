<?php

namespace App\Http\Controllers\Tenant;

use App\Events\TenantChatMessageSent;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Concerns\ResolvesTenantChatAccess;
use App\Models\ChatReport;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
        $resolveError = $this->resolveSendMessageReceiverId( $request );
        if ( $resolveError !== null ) {
            return response()->json( [
                'status' => 400,
                'error'  => $resolveError,
            ] );
        }

        $validator = Validator::make( $request->all(), [
            'message'     => 'required',
            'tenant_id'   => ['sometimes', function ( $attribute, $value, $fail ) {
                if ( (string) $value !== (string) tenant()->id ) {
                    $fail( 'The tenant id does not match the current tenant.' );
                }
            }],
            'receiver_id' => ['required', 'integer', Rule::exists( 'tenant.users', 'id' )],
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

    /**
     * Normalize {@see $request} `receiver_id` to tenant `users.id` (integer).
     * Accepts: numeric id, or string {@see User::$uniqid} via `receiver_id` or `receiver_uniqid`.
     *
     * @return array<string, array<int, string>>|null Validation-style errors, or null when ok / leave to Validator::required
     */
    private function resolveSendMessageReceiverId( Request $request ): ?array {
        $hasUniqid = Schema::connection( 'tenant' )->hasColumn( 'users', 'uniqid' );

        $uniqField = $request->input( 'receiver_uniqid' );
        if ( is_string( $uniqField ) ) {
            $uniqField = trim( $uniqField );
        }

        $raw = $request->input( 'receiver_id' );
        if ( is_string( $raw ) ) {
            $raw = trim( $raw );
        }

        if ( $uniqField !== null && $uniqField !== '' ) {
            if ( !$hasUniqid ) {
                return ['receiver_uniqid' => ['Tenant users have no uniqid column yet. Run tenant migrations, or send numeric receiver_id.']];
            }
            $uid = User::on( 'tenant' )->where( 'uniqid', $uniqField )->value( 'id' );
            if ( !$uid ) {
                return ['receiver_uniqid' => ['No user found for this receiver_uniqid.']];
            }
            $request->merge( ['receiver_id' => (int) $uid] );

            return null;
        }

        if ( $raw === null || $raw === '' ) {
            return null;
        }

        if ( is_bool( $raw ) ) {
            return ['receiver_id' => ['The receiver id must be a numeric user id or a user uniqid string.']];
        }

        if ( is_numeric( $raw ) ) {
            $asFloat = (float) $raw;
            if ( $asFloat != (int) $asFloat ) {
                return ['receiver_id' => ['The receiver id must be a whole number or a user uniqid string.']];
            }
            $request->merge( ['receiver_id' => (int) $asFloat] );

            return null;
        }

        if ( is_string( $raw ) ) {
            if ( !$hasUniqid ) {
                return ['receiver_id' => ['Non-numeric receiver_id requires a uniqid column on tenant users. Run tenant migrations or send a numeric user id.']];
            }

            // Common client mistake: sending tenant id instead of a user id / uniqid.
            if ( Tenant::on( 'mysql' )->where( 'id', $raw )->exists() ) {
                return ['receiver_id' => ['You sent a tenant id. Chat requires the receiver user id (tenant users.id). Send numeric receiver_id, or use receiver_uniqid after syncing uniqid into tenant users.']];
            }

            $uid = User::on( 'tenant' )->where( 'uniqid', $raw )->value( 'id' );
            if ( !$uid ) {
                return ['receiver_id' => ['No user found for this receiver id (tried as uniqid). Use numeric id or receiver_uniqid.']];
            }
            $request->merge( ['receiver_id' => (int) $uid] );

            return null;
        }

        return ['receiver_id' => ['The receiver id must be a numeric user id or a user uniqid string.']];
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
