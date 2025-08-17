<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DeliveryCompanyController extends Controller {
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        return response()->json( [
            'status'          => 200,
            'deliveryCompany' => DeliveryCompany::latest()->where( 'vendor_id', vendorId() )->select( 'id', 'company_name', 'phone', 'status' )->paginate( 10 ),
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

        $validator = Validator::make( $request->all(), [
            'company_name' => 'required|unique:delivery_companies,company_name,NULL,id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        }

        DeliveryCompany::create( [
            'user_id'      => Auth::id(),
            'vendor_id'    => vendorId(),
            'company_name' => $request->company_name,
            'company_slug' => Str::slug( $request->company_name ),
            'phone'        => $request->phone,
            'email'        => $request->email,
            'address'      => $request->address,
            'status'       => $request->status,
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Delivery company Added Successfully!',
        ] );
    }

    /**
     * Display the resource.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function edit( $id ) {

        $deliveryCompany = DeliveryCompany::select( 'id', 'company_name', 'phone', 'email', 'phone', 'status' )->find( $id );

        if ( !$deliveryCompany ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Not found!',
            ] );
        }
        return response()->json( [
            'status'  => 200,
            'message' => $deliveryCompany,
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
            'company_name' => 'required|unique:delivery_companies,company_name,' . $id . ',id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        } else {
            $deliveryCompany = DeliveryCompany::find( $id );

            if ( !$deliveryCompany ) {
                return response()->json( [
                    'status'  => 400,
                    'message' => 'Not found!',
                ] );
            }

            $deliveryCompany->company_name = $request->company_name;
            $deliveryCompany->company_slug = Str::slug( $request->company_name );
            $deliveryCompany->phone        = $request->phone;
            $deliveryCompany->email        = $request->email;
            $deliveryCompany->address      = $request->address;
            $deliveryCompany->user_id      = Auth::id();
            $deliveryCompany->save();

            return response()->json( [
                'status'  => 200,
                'message' => 'Delivery company Updated Successfully!',
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
        DeliveryCompany::find( $id )->delete();
        return response()->json( [
            'status'  => 200,
            'message' => 'Delivery company deleted Successfully !',
        ] );
    }

    /**
     * Change the resource status.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function status( $id ) {
        $data         = DeliveryCompany::find( $id );
        if(!$data){
            return response()->json([
                'status' => 400,
                'data' => vendorId(),
                'message' => 'Delivery company not found',
            ]);
        }

        // if($data->status == 'active'){
        //     return response()->json([
        //         'status' => 400,
        //         'message' => 'Delivery company already active',
        //     ]);
        // }

        $data->status = 'active';
        $data->save();


        $vendorDeliveryCompany = DeliveryCompany::whereNot( 'id', $id )->where( 'vendor_id', vendorId() )->whereStatus( 'active' )->get();

        if( $vendorDeliveryCompany->count() > 0 ){
            foreach( $vendorDeliveryCompany as $deliveryCompany ){
                if( $deliveryCompany->status == 'active' ){
                    $deliveryCompany->status = 'deactive';
                    $deliveryCompany->save();
                }
            }
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'Delivery company Active Successfully !',
        ] );
    }


    public function companyList() {
        $deliveryCompany = DeliveryCompany::latest()->where( 'vendor_id', vendorId() )
            ->whereStatus( 'active' )->select( 'id', 'company_name', 'phone', 'status' )->get();
        return response()->json( [
            'status'          => 200,
            'deliveryCompany' => $deliveryCompany,
        ] );
    }
}
