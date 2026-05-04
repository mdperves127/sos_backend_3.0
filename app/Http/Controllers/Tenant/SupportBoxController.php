<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\SupportBoxTicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupportBoxRequest;
use App\Http\Requests\TIcketReviewRequest;
use App\Http\Requests\VendorTicketReplayRequest;
use App\Models\SupportBox;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\SosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SupportBoxController extends Controller {
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        $datas = SupportBox::on( 'mysql' )
            ->where( 'tenant_id', tenant()->id )
            ->where( 'user_id', auth()->id() )
            ->withCount( 'ticketreplay as total_admin_replay' )
            ->with( ['latestTicketreplay', 'category:id,name', 'problem_topic:id,name'] )
            ->latest()
            ->paginate( 10 );

        foreach ( $datas as $supportBox ) {
            $this->hydrateSupportBoxUsers( $supportBox );
        }

        return $this->response( $datas );
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store( StoreSupportBoxRequest $request ) {
        $data = $request->all();
        SosService::ticketcreate( $data );
        return $this->response( 'Created successfull' );
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show( $id ) {

        $supportBox = SupportBox::on( 'mysql' )->where( [
            'id'        => $id,
            'tenant_id' => tenant()->id,
            'user_id'   => auth()->id(),
        ] )->first();

        if ( !$supportBox ) {
            return responsejson( 'Not found', 'fail' );
        }

        $data = $supportBox->load( ['ticketreplay' => function ( $query ) {
            $query->with( ['file' => function ( $fileRelation ) {
                $fileRelation->getQuery()->getQuery()->connection = DB::connection( 'mysql' );
                $fileRelation->getRelated()->setConnection( 'mysql' );
            }] );
        }] );
        $this->hydrateSupportBoxUsers( $data );

        TicketReply::on( 'mysql' )->where( 'support_box_id', $id )
            ->whereHas( 'supportBox', function ( $q ) {
                $q->where( 'tenant_id', tenant()->id )->where( 'user_id', auth()->id() );
            } )
            ->update( ['read_status' => 'read'] );

        return $this->response( $data );
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update( Request $request, $id ) {
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy( $id ) {
        $support = SupportBox::on( 'mysql' )->where( [
            'id'        => $id,
            'tenant_id' => tenant()->id,
            'user_id'   => auth()->id(),
        ] )->first();
        if ( !$support ) {
            return responsejson( 'Not found', 'fail' );
        }
        if ( $support->file && File::exists( $support->file ) ) {
            File::delete( $support->file );
        }
        $support->delete();

        return $this->response( 'Deleted successfull' );
    }

    function review( TIcketReviewRequest $request ) {
        $data        = $request->validated();
        $ticketReply = SupportBox::on( 'mysql' )->where( [
            'id'        => $data['support_box_id'],
            'tenant_id' => tenant()->id,
            'user_id'   => auth()->id(),
        ] )->first();

        if ( !$ticketReply ) {
            return responsejson( 'Not found', 'fail' );
        }
        $ticketReply->rating         = $data['rating'];
        $ticketReply->rating_comment = request( 'rating_comment' );
        $ticketReply->save();

        return $this->response( 'Rating successfull' );
    }

    function supportreplay( VendorTicketReplayRequest $request ) {

        $validateData                = $request->validated();
        $validateData['user_id']     = auth()->id();
        $validateData['read_status'] = "unread";
        $validateData['status']      = SupportBoxTicketStatus::Replied->value;

        $ticketreplay = TicketReply::on( 'mysql' )->create( $validateData );

        $newTicket = SupportBox::on( 'mysql' )->where( [
            'id'        => $validateData['support_box_id'],
            'tenant_id' => tenant()->id,
            'user_id'   => auth()->id(),
        ] )->first();
        if ( !$newTicket ) {
            return responsejson( 'Not found', 'fail' );
        }
        SupportBox::on( 'mysql' )->where( [
            'id'        => $validateData['support_box_id'],
            'tenant_id' => tenant()->id,
            'user_id'   => auth()->id(),
        ] )->update( [
            'status' => SupportBoxTicketStatus::Replied->value,
        ] );

        if ( request()->hasFile( 'file' ) ) {
            $filename = uploadany_file( request( 'file' ) );
            \App\Models\File::on( 'mysql' )->create( [
                'filetable_id'   => $ticketreplay->getKey(),
                'filetable_type' => $ticketreplay->getMorphClass(),
                'name'           => $filename,
            ] );
        }

        return $this->response( 'Successfull' );
    }

    function supportReplyCount() {
        $msgCount = TicketReply::on( 'mysql' )->where( 'read_status', 'unread' )
            ->where( 'user_id', '!=', auth()->id() )
            ->whereHas( 'supportBox', function ( $q ) {
                $q->where( 'tenant_id', tenant()->id )->where( 'user_id', auth()->id() );
            } )
            ->count();
        $boxScope = function ( $q ) {
            $q->where( 'tenant_id', tenant()->id )->where( 'user_id', auth()->id() );
        };
        $answer_ticket_count = SupportBox::on( 'mysql' )->where( $boxScope )->where( 'status', SupportBoxTicketStatus::Answered->value )->count();
        $reply_ticket_count  = SupportBox::on( 'mysql' )->where( $boxScope )->where( 'status', SupportBoxTicketStatus::Replied->value )->count();
        $new_ticket_count    = SupportBox::on( 'mysql' )->where( $boxScope )->where( 'status', SupportBoxTicketStatus::NewTicket->value )->count();

        return response()->json( [
            'status'              => 200,
            'msgCount'            => $msgCount,
            'answer_ticket_count' => $answer_ticket_count,
            'reply_ticket_count'  => $reply_ticket_count,
            'new_ticket_count'    => $new_ticket_count,
        ] );
    }

    public function supportCount() {
        $all_support = SupportBox::on( 'mysql' )
            ->where( 'tenant_id', tenant()->id )
            ->where( 'user_id', auth()->id() )
            ->withCount( 'ticketreplay as total_admin_replay' )
            ->with( ['latestTicketreplay', 'category:id,name', 'problem_topic:id,name'] )
            ->get();
        foreach ( $all_support as $supportBox ) {
            $this->hydrateSupportBoxUsers( $supportBox );
        }
        $closed = SupportBox::on( 'mysql' )
            ->where( 'tenant_id', tenant()->id )
            ->where( 'user_id', auth()->id() )
            ->where( function ( $q ) {
                $q->where( 'status', SupportBoxTicketStatus::Closed->value )->orWhere( 'is_close', 1 );
            } )
            ->count();

        return response()->json( [
            'closed'      => $closed,
            'all_support' => $all_support,
        ] );
    }

    /**
     * If this id exists in the current tenant's `users` table, use that row; otherwise use central `users`.
     * Uses a plain DB read for the tenant branch so Spatie/global scopes on {@see User} cannot force a fallback to mysql.
     */
    private function resolveSupportRelatedUser( ?int $userId ): ?User {
        if ( $userId === null || $userId === 0 ) {
            return null;
        }

        $tenantRow = DB::connection( 'tenant' )->table( 'users' )->where( 'id', $userId )->first();
        if ( $tenantRow !== null ) {
            return ( new User() )->newFromBuilder( (array) $tenantRow, 'tenant' );
        }

        $centralRow = DB::connection( 'mysql' )->table( 'users' )->where( 'id', $userId )->first();
        if ( $centralRow !== null ) {
            return ( new User() )->newFromBuilder( (array) $centralRow, 'mysql' );
        }

        return null;
    }

    private function hydrateSupportBoxUsers( SupportBox $supportBox ): void {
        $supportBox->setRelation( 'user', $this->resolveSupportRelatedUser( $supportBox->user_id ) );

        if ( $supportBox->relationLoaded( 'latestTicketreplay' ) && $supportBox->latestTicketreplay ) {
            $latest = $supportBox->latestTicketreplay;
            $latest->setRelation( 'user', $this->resolveSupportRelatedUser( $latest->user_id ) );
        }

        if ( $supportBox->relationLoaded( 'ticketreplay' ) ) {
            foreach ( $supportBox->ticketreplay as $reply ) {
                $reply->setRelation( 'user', $this->resolveSupportRelatedUser( $reply->user_id ) );
            }
        }
    }
}
