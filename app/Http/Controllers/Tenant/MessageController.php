<?php

namespace App\Http\Controllers\Tenant;

use App\Events\TenantChatMessageSent;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Concerns\ResolvesTenantChatAccess;
use App\Models\ChatReport;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CrossTenantQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MessageController extends Controller {
    use ResolvesTenantChatAccess;

    public function getMessages( int|string $tenantId ) {
        $tenantId = (string) $tenantId;
        $tid      = (string) tenant()->id;

        if ( ! Tenant::on( 'mysql' )->where( 'id', $tenantId )->exists() ) {
            return response()->json( [
                'success' => false,
                'message' => 'Tenant not found',
            ], 404 );
        }

        // Path param is the peer's tenant_id (mysql.tenants.id), not a local users.id.
        // Resolve the local chat user created/found via users.uniqid = tenant_id.
        $peerId = null;
        if ( Schema::connection( 'tenant' )->hasColumn( 'users', 'uniqid' ) ) {
            $peerId = User::on( 'tenant' )->where( 'uniqid', $tenantId )->value( 'id' );
        }

        if ( ! $peerId ) {
            return response()->json( ['success' => true, 'messages' => []] );
        }

        $peerId = (int) $peerId;

        // Shared inbox: any local staff can read the full thread with this external tenant peer.
        $messages = Message::on( 'tenant' )
            ->where( 'tenant_id', $tid )
            ->where( function ( $query ) use ( $peerId ) {
                $query->where( 'sender_id', $peerId )->orWhere( 'receiver_id', $peerId );
            } )
            ->orderBy( 'created_at' )
            ->get();

        $userIds = $messages->pluck( 'sender_id' )
            ->merge( $messages->pluck( 'receiver_id' ) )
            ->unique()
            ->filter( static fn ( $id ) => (int) $id > 0 )
            ->values();

        $usersById = $userIds->isEmpty()
            ? collect()
            : User::on( 'tenant' )->whereIn( 'id', $userIds )->get()->keyBy( 'id' );

        $messages->each( function ( Message $m ) use ( $usersById ) {
            $m->setRelation( 'sender', $usersById->get( (int) $m->sender_id ) );
            $m->setRelation( 'receiver', $usersById->get( (int) $m->receiver_id ) );
        } );

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

        $receiver = User::on( 'tenant' )->find( $receiverId );
        if ( !$receiver ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! This user not eligible to access this feature.',
            ], 401 );
        }

        // If the receiver is a synthetic "external tenant" chat user (users.uniqid = mysql.tenants.id),
        // the product_details based partner check does not apply (those rows store business user ids,
        // not the synthetic local user id). Subscription checks above still enforce chat eligibility.
        $receiverUniqid = $receiver->uniqid ?? null;
        $receiverIsExternalTenant =
            is_string( $receiverUniqid ) &&
            $receiverUniqid !== '' &&
            Tenant::on( 'mysql' )->where( 'id', $receiverUniqid )->exists();

        if ( !$receiverIsExternalTenant && !$this->tenantUsersAreChatPartners( $senderId, $receiverId ) ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! This user not eligible to access this feature.',
            ], 401 );
        }

        $localTenantId = (string) tenant()->id;

        $message              = new Message();
        $message->setConnection( 'tenant' );
        $message->tenant_id   = $localTenantId;
        $message->sender_id   = $senderId;
        $message->receiver_id = $receiverId;
        $message->user_id     = $senderId;
        $message->message     = $request->message;
        $message->conversation_id = 0;
        $message->save();

        $payload = $message->fresh()->toArray();

        event( new TenantChatMessageSent(
            $localTenantId,
            $senderId,
            $receiverId,
            $payload,
        ) );

        // Cross-tenant DM: also write into the peer tenant DB so their conversation list can see it.
        if ( $receiverIsExternalTenant ) {
            $this->mirrorMessageToPeerTenant(
                (string) $receiverUniqid,
                $localTenantId,
                (string) $request->message
            );
        }

        return response()->json( ['status' => 200] );
    }

    /**
     * Copy a chat message into the peer tenant database.
     * Local sender tenant is represented there as a synthetic user (users.uniqid = local tenant id).
     */
    private function mirrorMessageToPeerTenant(
        string $peerTenantId,
        string $localTenantId,
        string $messageText
    ): void {
        if ( $peerTenantId === '' || $peerTenantId === $localTenantId ) {
            return;
        }

        $peerTenant = Tenant::on( 'mysql' )->find( $peerTenantId );
        if ( ! $peerTenant ) {
            return;
        }

        $syntheticSenderId = $this->ensureChatUserOnTenant( $peerTenantId, $localTenantId );
        if ( $syntheticSenderId <= 0 ) {
            return;
        }

        $peerReceiverId = $this->resolvePeerInboxUserId( $peerTenantId, $syntheticSenderId );
        if ( $peerReceiverId <= 0 ) {
            return;
        }

        $peerMessage = CrossTenantQueryService::saveToTenant(
            $peerTenantId,
            Message::class,
            function ( Message $model ) use ( $peerTenantId, $syntheticSenderId, $peerReceiverId, $messageText ) {
                $model->tenant_id       = $peerTenantId;
                $model->sender_id       = $syntheticSenderId;
                $model->receiver_id     = $peerReceiverId;
                $model->user_id         = $syntheticSenderId;
                $model->message         = $messageText;
                $model->conversation_id = 0;
            }
        );

        if ( ! $peerMessage ) {
            return;
        }

        event( new TenantChatMessageSent(
            $peerTenantId,
            $syntheticSenderId,
            $peerReceiverId,
            $peerMessage->toArray(),
        ) );
    }

    /**
     * Ensure a synthetic chat user exists on {@see $targetTenantId}'s DB for {@see $representedTenantId}.
     */
    private function ensureChatUserOnTenant( string $targetTenantId, string $representedTenantId ): int {
        $existing = CrossTenantQueryService::getSingleFromTenant(
            $targetTenantId,
            User::class,
            function ( $query ) use ( $representedTenantId ) {
                $query->where( 'uniqid', $representedTenantId );
            }
        );

        if ( $existing ) {
            return (int) $existing->id;
        }

        $represented = Tenant::on( 'mysql' )->find( $representedTenantId );
        $name        = $represented?->company_name ?: ( 'Tenant ' . $representedTenantId );
        $email       = 'tenant-' . $representedTenantId . '@chat.local';

        $user = CrossTenantQueryService::saveToTenant(
            $targetTenantId,
            User::class,
            function ( User $u ) use ( $name, $email, $representedTenantId ) {
                $u->name      = $name;
                $u->email     = $email;
                $u->password  = bcrypt( str()->random( 24 ) );
                $u->last_seen = now();
                $u->uniqid    = $representedTenantId;

                $connection = $u->getConnectionName();
                if ( $connection && Schema::connection( $connection )->hasColumn( 'users', 'role_type' ) ) {
                    $u->role_type = 'tenant_user';
                }
            }
        );

        return $user ? (int) $user->id : 0;
    }

    /**
     * Pick who should receive the mirrored message on the peer tenant.
     * Prefer an existing thread participant; otherwise the first real (non-synthetic) user.
     */
    private function resolvePeerInboxUserId( string $peerTenantId, int $syntheticSenderId ): int {
        $prior = CrossTenantQueryService::getSingleFromTenant(
            $peerTenantId,
            Message::class,
            function ( $query ) use ( $syntheticSenderId ) {
                $query->where( function ( $q ) use ( $syntheticSenderId ) {
                    $q->where( 'sender_id', $syntheticSenderId )
                        ->orWhere( 'receiver_id', $syntheticSenderId );
                } )->orderByDesc( 'id' );
            }
        );

        if ( $prior ) {
            $other = (int) $prior->sender_id === $syntheticSenderId
                ? (int) $prior->receiver_id
                : (int) $prior->sender_id;
            if ( $other > 0 && $other !== $syntheticSenderId ) {
                return $other;
            }
        }

        $primary = CrossTenantQueryService::getSingleFromTenant(
            $peerTenantId,
            User::class,
            function ( $query ) {
                $query->where( function ( $q ) {
                    $q->whereNull( 'uniqid' )->orWhere( 'uniqid', '' );
                } )->orderBy( 'id' );
            }
        );

        if ( $primary ) {
            return (int) $primary->id;
        }

        $any = CrossTenantQueryService::getSingleFromTenant(
            $peerTenantId,
            User::class,
            function ( $query ) {
                $query->orderBy( 'id' );
            }
        );

        return $any ? (int) $any->id : 0;
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

            // Frontend often sends `mysql.tenants.id` (tenant/store id). In that case, we create/find
            // a local "chat user" row in the current tenant DB keyed by users.uniqid = tenant id.
            $tenant = Tenant::on( 'mysql' )->where( 'id', $raw )->first();
            if ( $tenant ) {
                $uid = $this->ensureTenantChatUserForExternalTenant( (string) $tenant->id );
                $request->merge( ['receiver_id' => (int) $uid] );
                return null;
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

    /**
     * Ensure there is a local tenant-db `users` row representing an external tenant (vendor/affiliate).
     * We store the external tenant id into tenant.users.uniqid, and use a synthetic unique email
     * to avoid collisions with the current tenant's real users.
     */
    private function ensureTenantChatUserForExternalTenant( string $externalTenantId ): int {
        $existingId = User::on( 'tenant' )
            ->where( 'uniqid', $externalTenantId )
            ->value( 'id' );
        if ( $existingId ) {
            return (int) $existingId;
        }

        $t = Tenant::on( 'mysql' )->find( $externalTenantId );
        $name = $t?->company_name ?: ( 'Tenant ' . $externalTenantId );

        // tenant.users.email is unique; use a synthetic value guaranteed unique per tenant id.
        $email = 'tenant-' . $externalTenantId . '@chat.local';

        $u = new User();
        $u->setConnection( 'tenant' );
        $u->name = $name;
        $u->email = $email;
        $u->password = bcrypt( str()->random( 24 ) );
        $u->last_seen = now();

        // Optional columns on tenant users
        if ( Schema::connection( 'tenant' )->hasColumn( 'users', 'role_type' ) ) {
            $u->role_type = 'tenant_user';
        }
        if ( Schema::connection( 'tenant' )->hasColumn( 'users', 'uniqid' ) ) {
            $u->uniqid = $externalTenantId;
        }

        $u->save();

        return (int) $u->id;
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
