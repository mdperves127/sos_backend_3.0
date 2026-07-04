<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Concerns\ResolvesCrossTenantChat;
use App\Http\Controllers\Tenant\Concerns\ResolvesTenantChatAccess;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;

class ConversationController extends Controller {
    use ResolvesTenantChatAccess;
    use ResolvesCrossTenantChat;

    /**
     * List conversation partners for the authenticated user.
     * Includes local threads and cross-tenant threads stored on peer tenant databases
     * (dropshipper→merchant messages live on the dropshipper DB until mirrored).
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

                if ( $externalUserIds->isNotEmpty() ) {
                    $q->orWhereIn( 'sender_id', $externalUserIds )
                        ->orWhereIn( 'receiver_id', $externalUserIds );
                }
            } )
            ->orderByDesc( 'created_at' )
            ->get();

        $byPartnerTenant = collect();

        if ( $messages->isNotEmpty() ) {
            $byPartner = $messages->reduce( function ( Collection $carry, Message $m ) use ( $me, $externalUserIds ) {
                $partnerId = $this->resolveConversationPartnerId( $m, $me, $externalUserIds );
                if ( $partnerId <= 0 || $partnerId === $me ) {
                    return $carry;
                }
                if ( !$carry->has( $partnerId ) ) {
                    $carry->put( $partnerId, [
                        'partner_id'   => $partnerId,
                        'last_message' => $m,
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

            $byPartnerTenant = $byPartner
                ->values()
                ->map( function ( array $row ) use ( $usersById, $meUser, $me ) {
                    $last = $row['last_message'];
                    $partnerId = (int) $row['partner_id'];
                    $thread = ( $row['messages'] ?? collect() )->reverse()->values();
                    $partnerUser = $usersById->get( $partnerId );
                    $partnerTenantId = $partnerUser->uniqid ?? null;

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
                        'partner_tenant_id' => $partnerTenantId,
                        'me'                => $meUser,
                        'user'              => $partnerUser,
                        'last_message'      => $last,
                        'messages'          => $thread,
                    ];
                } )
                ->keyBy( function ( array $row ) {
                    return (string) ( $row['partner_tenant_id'] ?: ( 'user:' . $row['partner_id'] ) );
                } );
        }

        // Pull threads that only exist on peer tenant DBs (typical dropshipper → merchant case).
        foreach ( $this->remoteCrossTenantConversations() as $remote ) {
            $key = (string) ( $remote['partner_tenant_id'] ?? '' );
            if ( $key === '' ) {
                continue;
            }

            if ( ! $byPartnerTenant->has( $key ) ) {
                $byPartnerTenant->put( $key, $remote );
                continue;
            }

            $local = $byPartnerTenant->get( $key );
            $merged = collect( $local['messages'] ?? [] )
                ->concat( $remote['messages'] ?? [] )
                ->unique( function ( Message $m ) {
                    return (string) $m->created_at . '|' . (string) $m->message . '|' . (int) $m->sender_id . '|' . (int) $m->receiver_id;
                } )
                ->sortBy( 'created_at' )
                ->values();

            $local['messages'] = $merged;
            $local['last_message'] = $merged->last() ?: $local['last_message'];
            $byPartnerTenant->put( $key, $local );
        }

        $conversations = $byPartnerTenant
            ->values()
            ->sortByDesc( static fn ( array $row ) => $row['last_message']?->created_at )
            ->values();

        return response()->json( [
            'status'        => 200,
            'conversations' => $conversations,
        ] );
    }

    /**
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
