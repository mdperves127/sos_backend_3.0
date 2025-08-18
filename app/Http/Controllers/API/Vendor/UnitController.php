<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class UnitController extends Controller {
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        return response()->json( [
            'status' => 200,
            'units'  => Unit::latest()->where( 'vendor_id', vendorId() )->get(),
        ] );
    }

    /**
     * Store the newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function store( Request $request ) {
        // $otherUserIds = [vendorId()];
        // $validator = Validator::make($request->all(), [
        //     'unit_name' => 'required',
        //     'unit_name' => [
        //         'required',
        //         Rule::unique('units')->where(function ($query) use ($otherUserIds) {
        //             return $query->whereIn('vendor_id', $otherUserIds);
        //         })
        //     ],
        // ]);

        $validator = Validator::make( $request->all(), [
            'unit_name' => 'required|unique:units,unit_name,NULL,id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ] );
        }

        Unit::create( [
            'user_id'   => Auth::id(),
            'vendor_id' => vendorId(),
            'unit_name' => $request->unit_name,
            'unit_slug' => Str::slug( $request->unit_name ),
            'status'    => $request->status,
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Unit Added Successfully!',
        ] );
    }

    /**
     * Display the resource.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function edit( $id ) {
        return response()->json( [
            'status'  => 200,
            'message' => Unit::find( $id ),
        ] );
    }

    /**
     * Update the resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function update( Request $request, $id ) {

        $validator = Validator::make( $request->all(), [
            'unit_name' => 'required|unique:units,unit_name,' . $id . ',id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ] );
        } else {
            $unit = Unit::find( $id );

            // Update only the fields that have changed
            $updateData = [
                'user_id'   => Auth::id(),
                'unit_slug' => Str::slug( $request->unit_name ),
            ];

            if ( $request->has( 'unit_name' ) ) {
                $updateData['unit_name'] = $request->unit_name;
            }

            if ( $request->has( 'status' ) ) {
                $updateData['status'] = $request->status;
            }

            $unit->update( $updateData );

            return response()->json( [
                'status'  => 200,
                'message' => 'Unit Updated Successfully!',
            ] );
        }
    }

    /**
     * Remove the resource from storage.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function destroy( $id ) {
        Unit::find( $id )->delete();
        return response()->json( [
            'status'  => 200,
            'message' => 'Unit Deleted Successfully !',
        ] );
    }

    /**
     * Change the resource status.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function status( $id ) {
        $data         = Unit::find( $id );
        $data->status = $data->status == 'active' ? 'deactive' : 'active';
        $data->save();

        if ( $data->status == 'active' ) {
            return response()->json( [
                'status'  => 200,
                'message' => 'Unit Active Successfully !',
            ] );
        } else {
            return response()->json( [
                'status'  => 200,
                'message' => 'Unit Deactive Successfully !',
            ] );
        }

    }
}
