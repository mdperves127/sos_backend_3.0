<?php

namespace App\Http\Controllers\API\Admin;

use App\Enums\SupportBoxTicketStatus;
use App\Enums\TicketReplyUserSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketReplyRequest;
use App\Http\Requests\UpdateTicketReplyRequest;
use App\Models\SupportBox;
use App\Models\TicketReply;

class TicketReplyController extends Controller {
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreTicketReplyRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store( StoreTicketReplyRequest $request ) {
        $validateData                = $request->validated();
        $validateData['user_id']     = userid();
        $validateData['user_source'] = TicketReplyUserSource::Admin->value;
        $validateData['status']      = SupportBoxTicketStatus::Answered->value;
        $validateData['read_status'] = 'unread';

        $ticketreplay = TicketReply::on( 'mysql' )->create( $validateData );

        SupportBox::on( 'mysql' )->where( 'id', $ticketreplay->support_box_id )->update( ['status' => SupportBoxTicketStatus::Answered->value] );

        if ( request()->hasFile( 'file' ) ) {
            $filename = uploadany_file( request( 'file' ) );
            $ticketreplay->file()->create( [
                'name' => $filename,
            ] );
        }

        return $this->response( 'Successfull' );
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\TicketReply  $ticketReply
     * @return \Illuminate\Http\Response
     */
    public function show( TicketReply $ticketReply ) {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateTicketReplyRequest  $request
     * @param  \App\Models\TicketReply  $ticketReply
     * @return \Illuminate\Http\Response
     */
    public function update( UpdateTicketReplyRequest $request, TicketReply $ticketReply ) {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\TicketReply  $ticketReply
     * @return \Illuminate\Http\Response
     */
    public function destroy( TicketReply $ticketReply ) {
        //
    }

    function closesupportbox( $id ) {
        $supportbox           = SupportBox::find( $id );
        $supportbox->is_close = 1;
        $supportbox->status   = SupportBoxTicketStatus::Closed->value;
        $supportbox->save();

        return $this->response( 'Ticket colse successfull!' );
    }

    function status( $id ) {
        $supportbox = SupportBox::find( $id );
        if ( !$supportbox ) {
            return response()->json( ['message' => 'Not found'], 404 );
        }
        $raw     = request( 'status' );
        $allowed = array_map( static fn ( SupportBoxTicketStatus $c ) => $c->value, SupportBoxTicketStatus::cases() );
        if ( !in_array( $raw, $allowed, true ) ) {
            return response()->json( ['message' => 'Invalid status'], 422 );
        }
        $supportbox->status = $raw;
        if ( $raw === SupportBoxTicketStatus::Closed->value ) {
            $supportbox->is_close = 1;
        }
        $supportbox->save();

        return "Status updated successfull!";
    }
}
