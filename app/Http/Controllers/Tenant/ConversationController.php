<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Concerns\ResolvesTenantChatAccess;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;

class ConversationController extends Controller {
    use ResolvesTenantChatAccess;

    /**
     * List conversation partners for the authenticated user, based on messages in this tenant DB.
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

        $messages = Message::on( 'tenant' )
            ->where( 'tenant_id', $tid )
            ->where( function ( $q ) use ( $me ) {
                $q->where( 'sender_id', $me )->orWhere( 'receiver_id', $me );
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
        $byPartner = $messages->reduce( function ( Collection $carry, Message $m ) use ( $me ) {
            $partnerId = (int) ( (int) $m->sender_id === $me ? $m->receiver_id : $m->sender_id );
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

                // Attach sender/receiver user objects to every message so the client
                // doesn't need to do extra lookups.
                $thread->each( function ( Message $m ) use ( $usersById, $meUser, $me ) {
                    $senderId = (int) $m->sender_id;
                    $receiverId = (int) $m->receiver_id;
                    $m->setRelation( 'sender', $senderId === $me ? $meUser : $usersById->get( $senderId ) );
                    $m->setRelation( 'receiver', $receiverId === $me ? $meUser : $usersById->get( $receiverId ) );
                } );

                return [
                    'partner_id'   => $partnerId,
                    'me'           => $meUser,
                    'user'         => $usersById->get( $partnerId ),
                    'last_message' => $last,
                    'messages'     => $thread,
                ];
            } )
            ->sortByDesc( static fn ( array $row ) => $row['last_message']?->created_at )
            ->values();

        return response()->json( [
            'status'        => 200,
            'conversations' => $conversations,
        ] );
    }
}
