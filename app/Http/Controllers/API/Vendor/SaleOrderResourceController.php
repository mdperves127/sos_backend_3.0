<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\SaleOrderResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

class SaleOrderResourceController extends Controller {
    /**
     * Store the newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function index() {
        return response()->json( [
            'status'   => 200,
            'resource' => SaleOrderResource::latest()->where( 'user_id', Auth::id() )->select( 'id', 'name', 'image', 'status' )->get(),
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
        //     'name' => 'required',
        //     'image' => 'nullable|image|max:1024',
        //     'name' => [
        //         'required',
        //         Rule::unique('sale_order_resources')->where(function ($query) use ($otherUserIds) {
        //             return $query->whereIn('vendor_id', $otherUserIds);
        //         })
        //     ],
        // ]);

        $validator = Validator::make( $request->all(), [
            'name'  => 'required|unique:sale_order_resources,name,NULL,id,vendor_id,' . vendorId(),
            'image' => 'nullable|image|max:1024',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        }

        SaleOrderResource::create( [
            'user_id'   => Auth::id(),
            'vendor_id' => vendorId(),
            'name'      => $request->name,
            'image'     => $request->hasFile( 'image' ) ? fileUpload( $request->file( 'image' ), 'uploads/resource', 100, 100 ) : null,
            'status'    => $request->status,
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Resource Added Successfully!',
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
            'message' => SaleOrderResource::find( $id ),
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
        // $currentUserId = [vendorId()];
        // $validator = Validator::make($request->all(), [
        //     'name' => [
        //         'required',
        //         Rule::unique('sale_order_resources')->where(function ($query) use ($currentUserId) {
        //             return $query->whereIn('vendor_id', $currentUserId);
        //         })->ignore($id)
        //     ],
        //     'image' => 'nullable|image|max:1024',
        // ]);

        $validator = Validator::make( $request->all(), [
            'name' => 'required|unique:sale_order_resources,name,' . $id . ',id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),

            ] );
        } else {
            $saleOrderResource = SaleOrderResource::find( $id );
            if ( $request->hasFile( 'image' ) ) {
                if ( $saleOrderResource->image ) {
                    File::delete( $saleOrderResource->image );
                }
                $filename                 = fileUpload( $request->file( 'image' ), 'uploads/resource', 100, 100 );
                $saleOrderResource->image = $filename;
            }

            // Update other fields
            $saleOrderResource->name   = $request->name;
            $saleOrderResource->status = $request->status;
            $saleOrderResource->save();

            return response()->json( [
                'status'  => 200,
                'message' => 'Resource Updated Successfully !',
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
        $data = SaleOrderResource::find( $id );
        if ( $data->image ) {
            File::delete( $data->image );
        }
        $data->delete();
        return response()->json( [
            'status'  => 200,
            'message' => 'Resource Deleted Successfully !',
        ] );
    }

    /**
     * Change the resource status.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function status( $id ) {
        $data         = SaleOrderResource::find( $id );
        $data->status = $data->status == 'active' ? 'deactive' : 'active';
        $data->save();

        if ( $data->status == 'active' ) {
            return response()->json( [
                'status'  => 200,
                'message' => 'Resource method Active Successfully !',
            ] );
        } else {
            return response()->json( [
                'status'  => 200,
                'message' => 'Resource method Deactive Successfully !',
            ] );
        }

    }
}
