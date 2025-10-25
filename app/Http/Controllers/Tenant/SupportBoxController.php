<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupportBoxRequest;
use App\Http\Requests\TIcketReviewRequest;
use App\Http\Requests\VendorTicketReplayRequest;
use App\Models\SupportBox;
use App\Models\TicketReply;
use App\Services\SosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class SupportBoxController extends Controller {
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        $datas = SupportBox::on( 'mysql' )->where( 'tenant_id', tenant()->id )
            ->withCount( ['ticketreplay as total_admin_replay' => function ( $query ) {
                $query->where( 'tenant_id', tenant()->id );
            }] )
            ->with( ['latestTicketreplay', 'category:id,name', 'problem_topic:id,name'] )
            ->latest()
            ->paginate( 10 );

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

        $supportBox = SupportBox::on( 'mysql' )->where( ['id' => $id, 'user_id' => userid()])->first();

        if ( !$supportBox ) {
            return responsejson( 'Not found', 'fail' );
        }

        $data = $supportBox->load( ['ticketreplay' => function ( $query ) {
            $query->with( ['file', 'user'] );
        }] );

        TicketReply::on( 'mysql' )->where( 'support_box_id', $id )->update( ['read_status' => 'read'] );

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
        $support = SupportBox::on( 'mysql' )->where( ['id' => $id, 'user_id' => userid()] )->first();
        if ( File::exists( $support->file ) ) {
            File::delete( $support->file );
        }
        $support->delete();

        return $this->response( 'Deleted successfull' );
    }

    function review( TIcketReviewRequest $request ) {
        $data        = $request->validated();
        $ticketReply = SupportBox::on( 'mysql' )->find( $data['support_box_id'] );

        if ( !$ticketReply ) {
            return responsejson( 'Not fond', 'fail' );
        }
        $ticketReply->rating         = $data['rating'];
        $ticketReply->rating_comment = request( 'rating_comment' );
        $ticketReply->save();

        return $this->response( 'Rating successfull' );
    }

    function supportreplay( VendorTicketReplayRequest $request ) {

        $validateData                = $request->validated();
        $validateData['user_id']     = userid();
        $validateData['read_status'] = "unread";
        $validateData['status']      = "replied";

        $ticketreplay = TicketReply::on( 'mysql' )->create( $validateData );

        $newTicket = SupportBox::on( 'mysql' )->where( 'id', $validateData['support_box_id'] )->first();
        SupportBox::on( 'mysql' )->where( 'id', $validateData['support_box_id'] )->update( [
            'status' => $newTicket->status == "new ticket" ? $newTicket->status : "replied",

        ] );

        if ( request()->hasFile( 'file' ) ) {
            $filename = uploadany_file( request( 'file' ) );
            $ticketreplay->file()->on( 'mysql' )->create( [
                'name' => $filename,
            ] );
        }

        return $this->response( 'Successfull' );
    }

    function supportReplyCount() {
        $msgCount            = TicketReply::on( 'mysql' )->where( 'user_id', userid() )->where( 'read_status', 'unread' )->count();
        $answer_ticket_count = SupportBox::on( 'mysql' )->where( 'status', 'answered' )->count();
        $reply_ticket_count  = SupportBox::on( 'mysql' )->where( 'status', 'replied' )->count();
        $new_ticket_count    = SupportBox::on( 'mysql' )->where( 'status', 'new ticket' )->count();

        return response()->json( [
            'status'              => 200,
            'msgCount'            => $msgCount,
            'answer_ticket_count' => $answer_ticket_count,
            'reply_ticket_count'  => $reply_ticket_count,
            'new_ticket_count'    => $new_ticket_count,
        ] );
    }

    public function supportCount() {
        $all_support = SupportBox::on( 'mysql' )->where( 'tenant_id', tenant()->id )
            ->withCount( ['ticketreplay as total_admin_replay' => function ( $query ) {
                $query->where( 'tenant_id', tenant()->id );
            }] )
            ->with( ['latestTicketreplay', 'category:id,name', 'problem_topic:id,name'] )
            ->get();
        $closed = SupportBox::on( 'mysql' )->where( 'user_id', userid() )->where( 'is_close', 1 )->count();

        return response()->json( [
            'closed'      => $closed,
            'all_support' => $all_support,
        ] );
    }
}
