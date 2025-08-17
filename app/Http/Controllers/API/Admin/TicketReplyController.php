<?php

namespace App\Http\Controllers\API\Admin;

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
        $validateData            = $request->validated();
        $validateData['user_id'] = userid();
        $validateData['status']  = "Answered";

        $ticketreplay = TicketReply::create( $validateData );

        SupportBox::where( 'id', $ticketreplay->support_box_id )->update( ['status' => 'answered'] );

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
        $supportbox->save();

        return $this->response( 'Ticket colse successfull!' );
    }

    function status( $id ) {
        $supportbox         = SupportBox::find( $id );
        $supportbox->status = request( 'status' );
        $supportbox->save();

        return "Status updated successfull!";
    }
}
