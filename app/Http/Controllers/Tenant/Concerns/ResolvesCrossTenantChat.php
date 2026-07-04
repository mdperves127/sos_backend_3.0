<?php

namespace App\Http\Controllers\Tenant\Concerns;

use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CrossTenantQueryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant ↔ dropshipper chat lives primarily in the sender tenant DB.
 * These helpers read the peer tenant DB and mirror writes so both panels stay in sync.
 */
trait ResolvesCrossTenantChat {
    /**
     * Peer tenants of the opposite type (merchant talks to dropshippers and vice versa).
     *
     * @return Collection<int, Tenant>
     */
    protected function chatPeerTenants(): Collection {
        $myId   = (string) tenant()->id;
        $myType = (string) ( tenant()->type ?? '' );

        $peerType = $myType === 'merchant' ? 'dropshipper' : ( $myType === 'dropshipper' ? 'merchant' : null );

        $query = Tenant::on( 'mysql' )->where( 'id', '!=', $myId );
        if ( $peerType ) {
            $query->where( 'type', $peerType );
        }

        return $query->get();
    }

    /**
     * Resolve external tenant id represented by a local chat user, if any.
     */
    protected function externalTenantIdFromUser( ?User $user ): ?string {
        if ( ! $user ) {
            return null;
        }

        $uniqid = $user->uniqid ?? null;
        if ( $uniqid !== null && $uniqid !== '' ) {
            $uniqid = (string) $uniqid;
            if ( Tenant::on( 'mysql' )->where( 'id', $uniqid )->exists() ) {
                return $uniqid;
            }
        }

        $email = (string) ( $user->email ?? '' );
        if ( preg_match( '/^tenant-(.+)@chat\.local$/', $email, $m ) ) {
            $candidate = $m[1];
            if ( Tenant::on( 'mysql' )->where( 'id', $candidate )->exists() ) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * Conversations stored on peer tenant databases where we appear as a synthetic user
     * (users.uniqid = current tenant id).
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function remoteCrossTenantConversations(): Collection {
        $myTenantId = (string) tenant()->id;
        $conversations = collect();

        foreach ( $this->chatPeerTenants() as $peer ) {
            try {
                $connection = CrossTenantQueryService::connectionForTenant( $peer );

                if ( ! Schema::connection( $connection )->hasColumn( 'users', 'uniqid' ) ) {
                    continue;
                }

                $syntheticMeId = (int) DB::connection( $connection )
                    ->table( 'users' )
                    ->where( 'uniqid', $myTenantId )
                    ->value( 'id' );

                if ( $syntheticMeId <= 0 ) {
                    continue;
                }

                $rows = DB::connection( $connection )
                    ->table( 'messages' )
                    ->whereNull( 'deleted_at' )
                    ->where( function ( $q ) use ( $syntheticMeId ) {
                        $q->where( 'sender_id', $syntheticMeId )
                            ->orWhere( 'receiver_id', $syntheticMeId );
                    } )
                    ->orderByDesc( 'created_at' )
                    ->get();

                if ( $rows->isEmpty() ) {
                    continue;
                }

                $localPartnerId = $this->ensureLocalChatUserForTenant( (string) $peer->id );
                $partnerUser    = User::on( 'tenant' )->find( $localPartnerId );
                $meUser         = User::on( 'tenant' )->find( (int) auth()->id() );

                $thread = $rows->sortBy( 'created_at' )->values()->map( function ( $row ) use ( $localPartnerId, $partnerUser, $meUser, $syntheticMeId ) {
                    $fromPeer = (int) $row->sender_id !== $syntheticMeId;
                    $message  = $this->hydrateRemoteMessage( $row, (string) ( $row->tenant_id ?? '' ) );

                    $sender   = $fromPeer ? $partnerUser : $meUser;
                    $receiver = $fromPeer ? $meUser : $partnerUser;
                    $message->setRelation( 'sender', $sender );
                    $message->setRelation( 'receiver', $receiver );
                    // Normalize ids to local synthetic/real users for the merchant panel UI.
                    $message->sender_id   = $fromPeer ? $localPartnerId : (int) ( $meUser->id ?? 0 );
                    $message->receiver_id = $fromPeer ? (int) ( $meUser->id ?? 0 ) : $localPartnerId;

                    return $message;
                } );

                $last = $thread->last();

                $conversations->put( (string) $peer->id, [
                    'partner_id'        => $localPartnerId,
                    'partner_tenant_id' => (string) $peer->id,
                    'me'                => $meUser,
                    'user'              => $partnerUser,
                    'last_message'      => $last,
                    'messages'          => $thread,
                ] );
            } catch ( \Throwable $e ) {
                Log::warning( 'remoteCrossTenantConversations failed for peer ' . $peer->id . ': ' . $e->getMessage() );
            }
        }

        return $conversations->values();
    }

    /**
     * Messages for a peer tenant, read from that peer's database (sender-side storage).
     *
     * @return Collection<int, Message>
     */
    protected function remoteMessagesWithPeerTenant( string $peerTenantId ): Collection {
        $myTenantId = (string) tenant()->id;
        $peer       = Tenant::on( 'mysql' )->find( $peerTenantId );
        if ( ! $peer ) {
            return collect();
        }

        try {
            $connection = CrossTenantQueryService::connectionForTenant( $peer );

            if ( ! Schema::connection( $connection )->hasColumn( 'users', 'uniqid' ) ) {
                return collect();
            }

            $syntheticMeId = (int) DB::connection( $connection )
                ->table( 'users' )
                ->where( 'uniqid', $myTenantId )
                ->value( 'id' );

            if ( $syntheticMeId <= 0 ) {
                return collect();
            }

            $rows = DB::connection( $connection )
                ->table( 'messages' )
                ->whereNull( 'deleted_at' )
                ->where( function ( $q ) use ( $syntheticMeId ) {
                    $q->where( 'sender_id', $syntheticMeId )
                        ->orWhere( 'receiver_id', $syntheticMeId );
                } )
                ->orderBy( 'created_at' )
                ->get();

            $localPartnerId = $this->ensureLocalChatUserForTenant( $peerTenantId );
            $partnerUser    = User::on( 'tenant' )->find( $localPartnerId );
            $meUser         = User::on( 'tenant' )->find( (int) auth()->id() );

            return $rows->map( function ( $row ) use ( $localPartnerId, $partnerUser, $meUser, $syntheticMeId ) {
                $fromPeer = (int) $row->sender_id !== $syntheticMeId;
                $message  = $this->hydrateRemoteMessage( $row, (string) ( $row->tenant_id ?? '' ) );

                $message->setRelation( 'sender', $fromPeer ? $partnerUser : $meUser );
                $message->setRelation( 'receiver', $fromPeer ? $meUser : $partnerUser );
                $message->sender_id   = $fromPeer ? $localPartnerId : (int) ( $meUser->id ?? 0 );
                $message->receiver_id = $fromPeer ? (int) ( $meUser->id ?? 0 ) : $localPartnerId;

                return $message;
            } )->values();
        } catch ( \Throwable $e ) {
            Log::warning( 'remoteMessagesWithPeerTenant failed for peer ' . $peerTenantId . ': ' . $e->getMessage() );

            return collect();
        }
    }

    /**
     * Mirror a local outbound message into the peer tenant database using direct DB writes.
     */
    protected function mirrorMessageToPeerTenant( string $peerTenantId, string $localTenantId, string $messageText ): void {
        if ( $peerTenantId === '' || $peerTenantId === $localTenantId ) {
            return;
        }

        $peer = Tenant::on( 'mysql' )->find( $peerTenantId );
        if ( ! $peer ) {
            return;
        }

        try {
            $connection = CrossTenantQueryService::connectionForTenant( $peer );

            $syntheticSenderId = $this->ensureChatUserOnConnection( $connection, $localTenantId );
            if ( $syntheticSenderId <= 0 ) {
                Log::error( 'mirrorMessageToPeerTenant: could not create synthetic sender', [
                    'peer'  => $peerTenantId,
                    'local' => $localTenantId,
                ] );

                return;
            }

            $peerReceiverId = $this->resolveInboxUserOnConnection( $connection, $syntheticSenderId );
            if ( $peerReceiverId <= 0 ) {
                Log::error( 'mirrorMessageToPeerTenant: no inbox user on peer', [
                    'peer' => $peerTenantId,
                ] );

                return;
            }

            $now = now();
            $payload = [
                'conversation_id' => 0,
                'sender_id'       => $syntheticSenderId,
                'receiver_id'     => $peerReceiverId,
                'user_id'         => $syntheticSenderId,
                'message'         => $messageText,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
            if ( Schema::connection( $connection )->hasColumn( 'messages', 'tenant_id' ) ) {
                $payload['tenant_id'] = $peerTenantId;
            }

            DB::connection( $connection )->table( 'messages' )->insert( $payload );
        } catch ( \Throwable $e ) {
            Log::error( 'mirrorMessageToPeerTenant failed: ' . $e->getMessage(), [
                'peer'  => $peerTenantId,
                'local' => $localTenantId,
            ] );
        }
    }

    /**
     * Ensure a local synthetic user exists for an external tenant id.
     */
    protected function ensureLocalChatUserForTenant( string $externalTenantId ): int {
        if ( Schema::connection( 'tenant' )->hasColumn( 'users', 'uniqid' ) ) {
            $existingId = User::on( 'tenant' )->where( 'uniqid', $externalTenantId )->value( 'id' );
            if ( $existingId ) {
                return (int) $existingId;
            }
        }

        $t     = Tenant::on( 'mysql' )->find( $externalTenantId );
        $name  = $t?->company_name ?: ( 'Tenant ' . $externalTenantId );
        $email = 'tenant-' . $externalTenantId . '@chat.local';

        $existingByEmail = User::on( 'tenant' )->where( 'email', $email )->value( 'id' );
        if ( $existingByEmail ) {
            $user = User::on( 'tenant' )->find( $existingByEmail );
            if ( $user && Schema::connection( 'tenant' )->hasColumn( 'users', 'uniqid' ) && ! $user->uniqid ) {
                $user->uniqid = $externalTenantId;
                $user->save();
            }

            return (int) $existingByEmail;
        }

        $u = new User();
        $u->setConnection( 'tenant' );
        $u->name      = $name;
        $u->email     = $email;
        $u->password  = bcrypt( str()->random( 24 ) );
        $u->last_seen = now();
        if ( Schema::connection( 'tenant' )->hasColumn( 'users', 'role_type' ) ) {
            $u->role_type = 'tenant_user';
        }
        if ( Schema::connection( 'tenant' )->hasColumn( 'users', 'uniqid' ) ) {
            $u->uniqid = $externalTenantId;
        }
        $u->save();

        return (int) $u->id;
    }

    protected function ensureChatUserOnConnection( string $connection, string $representedTenantId ): int {
        if ( Schema::connection( $connection )->hasColumn( 'users', 'uniqid' ) ) {
            $existingId = (int) DB::connection( $connection )
                ->table( 'users' )
                ->where( 'uniqid', $representedTenantId )
                ->value( 'id' );
            if ( $existingId > 0 ) {
                return $existingId;
            }
        }

        $email = 'tenant-' . $representedTenantId . '@chat.local';
        $existingByEmail = (int) DB::connection( $connection )
            ->table( 'users' )
            ->where( 'email', $email )
            ->value( 'id' );
        if ( $existingByEmail > 0 ) {
            if ( Schema::connection( $connection )->hasColumn( 'users', 'uniqid' ) ) {
                DB::connection( $connection )->table( 'users' )
                    ->where( 'id', $existingByEmail )
                    ->update( ['uniqid' => $representedTenantId] );
            }

            return $existingByEmail;
        }

        $represented = Tenant::on( 'mysql' )->find( $representedTenantId );
        $name        = $represented?->company_name ?: ( 'Tenant ' . $representedTenantId );
        $now         = now();

        $payload = [
            'name'       => $name,
            'email'      => $email,
            'password'   => bcrypt( str()->random( 24 ) ),
            'last_seen'  => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ( Schema::connection( $connection )->hasColumn( 'users', 'uniqid' ) ) {
            $payload['uniqid'] = $representedTenantId;
        }
        if ( Schema::connection( $connection )->hasColumn( 'users', 'role_type' ) ) {
            $payload['role_type'] = 'tenant_user';
        }

        return (int) DB::connection( $connection )->table( 'users' )->insertGetId( $payload );
    }

    protected function resolveInboxUserOnConnection( string $connection, int $syntheticSenderId ): int {
        $prior = DB::connection( $connection )
            ->table( 'messages' )
            ->whereNull( 'deleted_at' )
            ->where( function ( $q ) use ( $syntheticSenderId ) {
                $q->where( 'sender_id', $syntheticSenderId )
                    ->orWhere( 'receiver_id', $syntheticSenderId );
            } )
            ->orderByDesc( 'id' )
            ->first();

        if ( $prior ) {
            $other = (int) $prior->sender_id === $syntheticSenderId
                ? (int) $prior->receiver_id
                : (int) $prior->sender_id;
            if ( $other > 0 && $other !== $syntheticSenderId ) {
                return $other;
            }
        }

        if ( Schema::connection( $connection )->hasColumn( 'users', 'role_type' ) ) {
            $adminId = (int) DB::connection( $connection )->table( 'users' )
                ->where( 'email', 'not like', 'tenant-%@chat.local' )
                ->where( 'role_type', 'admin' )
                ->orderBy( 'id' )
                ->value( 'id' );
            if ( $adminId > 0 ) {
                return $adminId;
            }
        }

        $primaryId = (int) DB::connection( $connection )->table( 'users' )
            ->where( 'email', 'not like', 'tenant-%@chat.local' )
            ->orderBy( 'id' )
            ->value( 'id' );
        if ( $primaryId > 0 ) {
            return $primaryId;
        }

        return (int) DB::connection( $connection )->table( 'users' )->orderBy( 'id' )->value( 'id' );
    }

    protected function hydrateRemoteMessage( object $row, string $tenantId ): Message {
        $message = new Message();
        $message->setConnection( 'tenant' );
        $message->forceFill( [
            'id'              => $row->id ?? null,
            'tenant_id'       => $tenantId !== '' ? $tenantId : ( $row->tenant_id ?? null ),
            'conversation_id' => $row->conversation_id ?? 0,
            'sender_id'       => $row->sender_id ?? null,
            'receiver_id'     => $row->receiver_id ?? null,
            'user_id'         => $row->user_id ?? null,
            'message'         => $row->message ?? '',
            'created_at'      => $row->created_at ?? null,
            'updated_at'      => $row->updated_at ?? null,
        ] );
        $message->exists = true;

        return $message;
    }
}
