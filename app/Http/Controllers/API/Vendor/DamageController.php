<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Damage;
use App\Models\DamageDetails;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DamageController extends Controller {
    public function index() {
        $startDate = request()->input( 'start_date' );
        $endDate   = request()->input( 'end_date' );

        $damages = Damage::latest()
            ->when( $startDate && $endDate, function ( $query ) use ( $startDate, $endDate ) {
                $startDate = \Carbon\Carbon::parse( $startDate )->startOfDay();
                $endDate   = \Carbon\Carbon::parse( $endDate )->endOfDay();
                return $query->whereBetween( 'created_at', [$startDate, $endDate] );
            } )
            ->with( 'damage_details', function ( $q ) {
                $q->select( 'id', 'damage_id', 'unit_id', 'size_id', 'color_id', 'damage_qty', 'rate', 'sub_total', 'remark' )->with( 'color', 'size', 'unit' );
            } )
            ->with( 'user', function ( $q ) {
                $q->select( 'id', 'name', 'email', \DB::raw( 'UPPER(uniqid) AS uniqid' ) );
            } )
            ->with( 'product:id,name' )
            ->where( 'vendor_id', vendorId() )
            ->get();

        return response()->json( [
            'status'  => 200,
            'damages' => $damages,
        ] );
    }

    public function store( Request $request ) {

        $product = Product::where( [
            'id'        => $request->product_id,
            'vendor_id' => vendorId(),
        ] )->first();
        if ( !$product ) {
            return response()->json( [
                'status'  => 404,
                'message' => "Product not found",
            ] );
        }

        $damage             = new Damage();
        $damage->product_id = $request->product_id;
        $damage->user_id    = Auth::id();
        $damage->vendor_id  = vendorId();
        $damage->note       = $request->note;
        $damage->qty        = $request->qty;
        $damage->save();

        $variant_ids = $request->variant_id;

        foreach ( $variant_ids as $key => $variant_id ) {
            $variant = ProductVariant::find( $variant_id );

            $damageDetails             = new DamageDetails();
            $damageDetails->damage_id  = $damage->id;
            $damageDetails->unit_id    = $variant->unit_id;
            $damageDetails->size_id    = $variant->size_id;
            $damageDetails->color_id   = $variant->color_id;
            $damageDetails->damage_qty = $request->damage_qty[$key];
            $damageDetails->rate       = $product->selling_price;
            $damageDetails->sub_total  = $product->selling_price * $request->damage_qty[$key];
            $damageDetails->remark     = $request->remark[$key];
            $damageDetails->save();

            $variant->decrement( 'qty', $request->damage_qty[$key] );
        }

        $product->decrement( 'qty', $request->qty );

        return response()->json( [
            'status'  => 200,
            'message' => "Successfully damage store",
        ] );
    }
}
