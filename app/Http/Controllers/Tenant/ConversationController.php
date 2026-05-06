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

        /** @var Collection<int, array{partner_id:int,last_message:Message}> $byPartner */
        $byPartner = $messages->reduce( function ( Collection $carry, Message $m ) use ( $me ) {
            $partnerId = (int) ( (int) $m->sender_id === $me ? $m->receiver_id : $m->sender_id );
            if ( $partnerId <= 0 || $partnerId === $me ) {
                return $carry;
            }
            if ( !$carry->has( $partnerId ) ) {
                $carry->put( $partnerId, [
                    'partner_id'   => $partnerId,
                    'last_message' => $m,
                ] );
            }
            return $carry;
        }, collect() );

        $partnerIds = $byPartner->keys()->values();
        $usersById = User::on( 'tenant' )
            ->whereIn( 'id', $partnerIds )
            ->get()
            ->keyBy( 'id' );

        $conversations = $byPartner
            ->values()
            ->map( function ( array $row ) use ( $usersById ) {
                /** @var Message $last */
                $last = $row['last_message'];
                $partnerId = (int) $row['partner_id'];
                return [
                    'partner_id'   => $partnerId,
                    'user'         => $usersById->get( $partnerId ),
                    'last_message' => $last,
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
