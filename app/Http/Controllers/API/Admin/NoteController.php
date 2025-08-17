<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAdvertise;
use App\Models\Note;
use App\Models\PaymentHistory;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NoteController extends Controller {

    public function index() {

        return response()->json( [
            'status' => 200,
            'Note'   => Note::latest()->get(),
        ] );
    }

    public function store( Request $request ) {
        $validator = Validator::make( $request->all(), [
            'note' => 'required',
        ] );
        if ( $validator->fails() ) {
            return response()->json( [
                'validation_errors' => $validator->messages(),
            ] );
        }

        Note::create( [
            'user_id' => $request->user_id,
            'note'    => $request->note,
            'status'  => "unread",
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Note Added Successfully!',
        ] );
    }

    public function vendorNote( $id ) {

        $notes = Note::where( 'user_id', $id )->paginate( 10 );

        return response()->json( [
            'status' => 200,
            'notes'  => $notes,
        ] );

    }

    public function vendorAdvertise( $id ) {

        $advertise = AdminAdvertise::where( 'user_id', $id )->paginate( 10 );

        return response()->json( [
            'status'    => 200,
            'advertise' => $advertise,
        ] );

    }

    public function vendorServiceOrder( $id ) {

        $serviceOrder = ServiceOrder::where( 'user_id', $id )->with( ['servicedetails:id,title', 'packagedetails:id,package_title', 'vendor:id,name'] )->paginate( 10 );

        return response()->json( [
            'status'       => 200,
            'serviceOrder' => $serviceOrder,
        ] );

    }

    public function vendorPaymentHistory( $id ) {

        $paymentHistory = PaymentHistory::where( 'user_id', $id )->paginate( 10 );

        return response()->json( [
            'status'       => 200,
            'serviceOrder' => $paymentHistory,
        ] );

    }
}
