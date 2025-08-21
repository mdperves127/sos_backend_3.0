<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PaymentMethodController extends Controller {
    /**
     * Store the newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function index() {
        return response()->json( [
            'status' => 200,
            'data'   => PaymentMethod::latest()->where( 'user_id', Auth::id() )->select( 'id', 'payment_method_name', 'status', 'acc_no' )->get(),
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
        //     'payment_method_name' => 'required',
        //     'payment_method_name' => [
        //         'required',
        //         Rule::unique('payment_methods')->where(function ($query) use ($otherUserIds) {
        //             return $query->whereIn('vendor_id', $otherUserIds);
        //         })
        //     ],
        // ]);

        $validator = Validator::make( $request->all(), [
            'payment_method_name' => 'required|unique:payment_methods,payment_method_name,NULL,id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        }

        PaymentMethod::create( [
            'user_id'             => Auth::id(),
            'payment_method_name' => $request->payment_method_name,
            'payment_method_slug' => Str::slug( $request->payment_method_name ),
            'acc_no'              => $request->acc_no,
            'status'              => $request->status,
            'vendor_id'           => vendorId(),
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Payment method Added Successfully!',
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
            'message' => PaymentMethod::find( $id ),
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
        // $currentUserId = vendorId();
        // $rules = [
        //     'payment_method_name' => [
        //         'required',
        //         Rule::unique('payment_methods')->where(function ($query) use ($currentUserId) {
        //             return $query->where('vendor_id', $currentUserId);
        //         })->ignore($id), // Ignore the current payment method ID when checking uniqueness
        //     ],
        // ];

        // // Check if payment_method_name is present and not empty
        // if ($request->has('payment_method_name') && !empty($request->payment_method_name)) {
        //     // Add other validation rules for 'payment_method_name' if needed
        // }

        // $validator = Validator::make($request->all(), $rules);

        $validator = Validator::make( $request->all(), [
            'payment_method_name' => 'required|unique:payment_methods,payment_method_name,' . $id . ',id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        } else {
            $paymentMethod = PaymentMethod::find( $id );

            if ( $paymentMethod ) {
                // Update only the fields that have changed
                $updateData = [
                    'payment_method_name' => $request->payment_method_name,
                    'payment_method_slug' => Str::slug( $request->payment_method_name ),
                ];

                if ( $request->has( 'acc_no' ) ) {
                    $updateData['acc_no'] = $request->acc_no;
                }

                if ( $request->has( 'status' ) ) {
                    $updateData['status'] = $request->status;
                }

                $paymentMethod->update( $updateData );

                return response()->json( [
                    'status'  => 200,
                    'message' => 'Payment method Updated Successfully!',
                ] );
            } else {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'No Payment method ID Found',
                ] );
            }
        }
    }

    /**
     * Remove the resource from storage.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function destroy( $id ) {
        PaymentMethod::find( $id )->delete();
        return response()->json( [
            'status'  => 200,
            'message' => 'Payment method Deleted Successfully !',
        ] );
    }

    /**
     * Change the resource status.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function status( $id ) {
        $data         = PaymentMethod::find( $id );
        $data->status = $data->status == 'active' ? 'deactive' : 'active';
        $data->save();

        if ( $data->status == 'active' ) {
            return response()->json( [
                'status'  => 200,
                'message' => 'Payment method Active Successfully !',
            ] );
        } else {
            return response()->json( [
                'status'  => 200,
                'message' => 'Payment method Deactive Successfully !',
            ] );
        }

    }
}
