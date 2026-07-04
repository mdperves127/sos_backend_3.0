<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Concerns\ResolvesTenantChatAccess;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;

class ConversationController extends Controller {
    use ResolvesTenantChatAccess;

    /**
     * List conversation partners for the authenticated user, based on messages in this tenant DB.
     * Includes cross-tenant (merchant ↔ dropshipper) threads for any staff user on this tenant.
     */
    public function index() {
        $sub = $this->tenantChatSubscription();
        if ( !$sub ) {
            return $this->chatAccessDeniedResponse();
        }
        if ( !$this->tenantHasChatAccess( $sub ) ) {
            return $this->chatPlanDeniedResponse();
        }

        $me = (int) Auth::id();
        $tid = (string) tenant()->id;
        $externalUserIds = $this->externalTenantChatUserIds();

        $messages = Message::on( 'tenant' )
            ->where( 'tenant_id', $tid )
            ->where( function ( $q ) use ( $me, $externalUserIds ) {
                $q->where( 'sender_id', $me )->orWhere( 'receiver_id', $me );

                // Shared inbox: any local user can see threads with external tenants.
                if ( $externalUserIds->isNotEmpty() ) {
                    $q->orWhereIn( 'sender_id', $externalUserIds )
                        ->orWhereIn( 'receiver_id', $externalUserIds );
                }
            } )
            ->orderByDesc( 'created_at' )
            ->get();

        if ( $messages->isEmpty() ) {
            return response()->json( [
                'status'        => 200,
                'conversations' => [],
            ] );
        }

        /** @var Collection<int, array{partner_id:int,last_message:Message,messages:Collection<int,Message>}> $byPartner */
        $byPartner = $messages->reduce( function ( Collection $carry, Message $m ) use ( $me, $externalUserIds ) {
            $partnerId = $this->resolveConversationPartnerId( $m, $me, $externalUserIds );
            if ( $partnerId <= 0 || $partnerId === $me ) {
                return $carry;
            }
            if ( !$carry->has( $partnerId ) ) {
                $carry->put( $partnerId, [
                    'partner_id'   => $partnerId,
                    'last_message' => $m, // first seen is latest because messages are desc
                    'messages'     => collect(),
                ] );
            }
            $row = $carry->get( $partnerId );
            $row['messages']->push( $m );
            $carry->put( $partnerId, $row );
            return $carry;
        }, collect() );

        $partnerIds = $byPartner->keys()->values();
        $usersById = User::on( 'tenant' )
            ->whereIn( 'id', $partnerIds )
            ->get()
            ->keyBy( 'id' );

        $meUser = User::on( 'tenant' )->find( $me );

        $conversations = $byPartner
            ->values()
            ->map( function ( array $row ) use ( $usersById, $meUser, $me ) {
                /** @var Message $last */
                $last = $row['last_message'];
                $partnerId = (int) $row['partner_id'];

                /** @var Collection<int, Message> $thread */
                $thread = $row['messages'] ?? collect();

                // messages were collected in desc order; return in asc for chat UI.
                $thread = $thread->reverse()->values();

                $partnerUser = $usersById->get( $partnerId );

                // Attach sender/receiver user objects to every message so the client
                // doesn't need to do extra lookups.
                $thread->each( function ( Message $m ) use ( $usersById, $meUser, $me, $partnerUser, $partnerId ) {
                    $senderId = (int) $m->sender_id;
                    $receiverId = (int) $m->receiver_id;
                    $m->setRelation(
                        'sender',
                        $senderId === $me ? $meUser : ( $senderId === $partnerId ? $partnerUser : $usersById->get( $senderId ) )
                    );
                    $m->setRelation(
                        'receiver',
                        $receiverId === $me ? $meUser : ( $receiverId === $partnerId ? $partnerUser : $usersById->get( $receiverId ) )
                    );
                } );

                return [
                    'partner_id'        => $partnerId,
                    'partner_tenant_id' => $partnerUser->uniqid ?? null,
                    'me'                => $meUser,
                    'user'              => $partnerUser,
                    'last_message'      => $last,
                    'messages'          => $thread,
                ];
            } )
            ->sortByDesc( static fn ( array $row ) => $row['last_message']?->created_at )
            ->values();

        return response()->json( [
            'status'        => 200,
            'conversations' => $conversations,
        ] );
    }

    /**
     * Local user ids that represent an external tenant (users.uniqid = mysql.tenants.id).
     *
     * @return Collection<int, int>
     */
    private function externalTenantChatUserIds(): Collection {
        if ( ! Schema::connection( 'tenant' )->hasColumn( 'users', 'uniqid' ) ) {
            return collect();
        }

        $users = User::on( 'tenant' )
            ->whereNotNull( 'uniqid' )
            ->where( 'uniqid', '!=', '' )
            ->get( ['id', 'uniqid'] );

        if ( $users->isEmpty() ) {
            return collect();
        }

        $tenantIds = Tenant::on( 'mysql' )
            ->whereIn( 'id', $users->pluck( 'uniqid' )->unique()->values()->all() )
            ->pluck( 'id' )
            ->map( static fn ( $id ) => (string) $id )
            ->all();

        return $users
            ->filter( static fn ( $user ) => in_array( (string) $user->uniqid, $tenantIds, true ) )
            ->pluck( 'id' )
            ->map( static fn ( $id ) => (int) $id )
            ->values();
    }

    /**
     * For cross-tenant threads, the partner is always the external synthetic user.
     */
    private function resolveConversationPartnerId( Message $m, int $me, Collection $externalUserIds ): int {
        $senderId = (int) $m->sender_id;
        $receiverId = (int) $m->receiver_id;

        $senderIsExternal = $externalUserIds->contains( $senderId );
        $receiverIsExternal = $externalUserIds->contains( $receiverId );

        if ( $senderIsExternal && ! $receiverIsExternal ) {
            return $senderId;
        }
        if ( $receiverIsExternal && ! $senderIsExternal ) {
            return $receiverId;
        }

        return (int) ( $senderId === $me ? $receiverId : $senderId );
    }
}
