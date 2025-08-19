<?php

namespace App\Http\Controllers\API;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Size;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SizeController extends Controller {
    public function SizeIndex() {
        $size = Size::where( 'user_id', auth()->user()->id )
            ->when( request( 'status' ) == 'active', function ( $q ) {
                return $q->where( 'status', 'active' );
            } )
            ->latest()
            ->get();

        return response()->json( [
            'status' => 200,
            'size'   => $size,
        ] );
    }

    public function SizeStore( Request $request ) {

        $validator = Validator::make( $request->all(), [
            'name' => 'required|unique:sizes,name,NULL,id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ] );
        } else {
            $size             = new Size();
            $size->name       = $request->input( 'name' );
            $size->slug       = slugCreate( Size::class, $request->name );
            $size->user_id    = Auth::id();
            $size->status     = $request->status;
            $size->created_by = Status::Vendor->value;
            $size->vendor_id  = vendorId();
            $size->save();
            return response()->json( [
                'status'  => 200,
                'message' => 'Size Added Successfully',
            ] );
        }
    }

    public function SizeEdit( $id ) {
        $userId = Auth::id();
        $size   = Size::where( 'user_id', $userId )->find( $id );
        if ( $size ) {
            return response()->json( [
                'status' => 200,
                'size'   => $size,
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Size Id Found',
            ] );
        }
    }

    public function SizeUpdate( Request $request, $id ) {

        $validator = Validator::make( $request->all(), [
            'name' => 'required|unique:sizes,name,' . $id . ',id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ] );
        } else {
            $size = Size::find( $id );
            if ( $size ) {
                $size->name = $request->input( 'name' );
                $size->slug = slugUpdate( Size::class, $request->name, $id );

                // Update status only if it is present in the request
                if ( $request->has( 'status' ) ) {
                    $size->status = $request->input( 'status' );
                }

                $size->user_id = Auth::id();
                $size->save();

                return response()->json( [
                    'status'  => 200,
                    'message' => 'Size Updated Successfully',
                ] );
            } else {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'No Size ID Found',
                ] );
            }
        }
    }

    public function destroy( $id ) {
        $userId = Auth::id();
        $size   = Size::where( 'user_id', $userId )->find( $id );
        if ( $size ) {
            $size->delete();
            return response()->json( [
                'status'  => 200,
                'message' => 'Size Deleted Successfully',
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Size ID Found',
            ] );
        }
    }
}
