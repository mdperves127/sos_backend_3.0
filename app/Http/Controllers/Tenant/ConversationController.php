<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Concerns\ResolvesTenantChatAccess;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class ConversationController extends Controller {
    use ResolvesTenantChatAccess;

    /**
     * List users who appear on active {@see ProductDetails} for this {@see tenant()->id} (tenant_id only).
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

        $partnerIds = $this->tenantCatalogParticipantUserIds()
            ->filter( static fn ( int $id ) => $id !== $me )
            ->values();

        $conversationUsers = User::on( 'tenant' )
            ->whereIn( 'id', $partnerIds )
            ->get();

        Conversation::on( 'tenant' )->where( 'sender_id', Auth::id() )
            ->orWhere( 'receiver_id', Auth::id() )
            ->get();

        return response()->json( [
            'status'        => 200,
            'conversations' => $conversationUsers,
        ] );
    }
}
