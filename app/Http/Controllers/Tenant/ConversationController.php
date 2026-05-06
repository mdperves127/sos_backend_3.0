<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Concerns\ResolvesTenantChatAccess;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

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

        $partnerIds = Message::on( 'tenant' )
            ->where( 'tenant_id', $tid )
            ->where( function ( $q ) use ( $me ) {
                $q->where( 'sender_id', $me )->orWhere( 'receiver_id', $me );
            } )
            ->get( ['sender_id', 'receiver_id'] )
            ->flatMap( static fn ( $m ) => [(int) $m->sender_id, (int) $m->receiver_id] )
            ->filter( static fn ( int $id ) => $id > 0 && $id !== $me )
            ->unique()
            ->values();

        $conversationUsers = $partnerIds->isEmpty()
            ? collect()
            : User::on( 'tenant' )->whereIn( 'id', $partnerIds )->get();

        return response()->json( [
            'status'        => 200,
            'conversations' => $conversationUsers,
        ] );
    }
}
