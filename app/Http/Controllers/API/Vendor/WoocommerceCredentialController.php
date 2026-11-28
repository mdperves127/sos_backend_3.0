<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\WoocommerceCredential;
use App\Services\WooCommerceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WoocommerceCredentialController extends Controller {
    public function __construct( protected WooCommerceService $woo ) {
    }

    public function index() {
        $data = WoocommerceCredential::where( 'vendor_id', vendorId() )
            ->latest( 'id' )
            ->get()
            ->map( fn ( WoocommerceCredential $row ) => $row->toPublicArray() );

        return response()->json( [
            'status' => 200,
            'data'   => $data,
        ] );
    }

    public function store( Request $request ) {
        $validator = Validator::make( $request->all(), [
            'wc_key'    => 'required',
            'wc_secret' => 'required',
            'wc_url'    => 'required|url',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ], 400 );
        }

        $url    = rtrim( trim( (string) $request->wc_url ), '/' );
        $key    = trim( (string) $request->wc_key );
        $secret = trim( (string) $request->wc_secret );

        $taken = WoocommerceCredential::withTrashed()
            ->where( 'wc_url', $url )
            ->where( 'vendor_id', '!=', vendorId() )
            ->exists();
        if ( $taken ) {
            return response()->json( [
                'status' => 400,
                'errors' => ['wc_url' => ['This website is already registered with another vendor']],
            ], 400 );
        }

        try {
            $this->woo->validateConnection( $url, $key, $secret );
        } catch ( \Throwable $e ) {
            return response()->json( [
                'status'  => 422,
                'message' => $e->getMessage(),
            ], 422 );
        }

        // Reuse / restore this vendor's row after delete or disconnect.
        $data = WoocommerceCredential::withTrashed()
            ->where( 'vendor_id', vendorId() )
            ->where( 'wc_url', $url )
            ->latest( 'id' )
            ->first()
            ?: WoocommerceCredential::withTrashed()
                ->where( 'vendor_id', vendorId() )
                ->latest( 'id' )
                ->first();

        if ( ! $data ) {
            $data = new WoocommerceCredential();
        } elseif ( method_exists( $data, 'trashed' ) && $data->trashed() ) {
            $data->restore();
        }

        WoocommerceCredential::withTrashed()
            ->where( 'vendor_id', vendorId() )
            ->when( $data->id, fn ( $q ) => $q->where( 'id', '!=', $data->id ) )
            ->update( ['is_active' => false] );

        $data->vendor_id          = vendorId();
        $data->wc_key             = $key;
        $data->wc_secret          = $secret;
        $data->wc_url             = $url;
        $data->status             = WoocommerceCredential::STATUS_CONNECTED;
        $data->connection_message = 'Connected';
        $data->is_active          = true;
        $data->sync_direction     = $data->sync_direction ?: WoocommerceCredential::DIRECTION_TWO_WAY;
        $data->product_sync       = $data->product_sync ?? true;
        $data->order_sync         = $data->order_sync ?? true;
        $data->stock_sync         = $data->stock_sync ?? true;
        $data->auto_sync          = $data->auto_sync ?? true;
        $data->webhook_secret     = $data->webhook_secret ?: Str::random( 32 );
        $data->last_error         = null;
        $data->save();

        return response()->json( [
            'status'  => 200,
            'message' => 'Created successfull',
            'data'    => $data->toPublicArray(),
        ] );
    }

    public function edit( $id ) {
        $data = WoocommerceCredential::where( 'vendor_id', vendorId() )->find( $id );
        if ( ! $data ) {
            return response()->json( ['status' => 404, 'message' => 'Not found'], 404 );
        }

        return response()->json( [
            'status' => 200,
            'data'   => $data->toPublicArray(),
        ] );
    }

    public function update( Request $request, $id ) {
        $data = WoocommerceCredential::where( 'vendor_id', vendorId() )->find( $id );
        if ( ! $data ) {
            return response()->json( ['status' => 404, 'message' => 'Not found'], 404 );
        }

        $validator = Validator::make( $request->all(), [
            'wc_key'    => 'required',
            'wc_secret' => 'required',
            'wc_url'    => 'required|url',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ], 400 );
        }

        $url    = rtrim( trim( (string) $request->wc_url ), '/' );
        $key    = trim( (string) $request->wc_key );
        $secret = trim( (string) $request->wc_secret );

        try {
            $this->woo->validateConnection( $url, $key, $secret );
        } catch ( \Throwable $e ) {
            return response()->json( [
                'status'  => 422,
                'message' => $e->getMessage(),
            ], 422 );
        }

        $data->wc_key             = $key;
        $data->wc_secret          = $secret;
        $data->wc_url             = $url;
        $data->status             = WoocommerceCredential::STATUS_CONNECTED;
        $data->connection_message = 'Updated and validated';
        $data->is_active          = true;
        $data->last_error         = null;
        $data->save();

        return response()->json( [
            'status'  => 200,
            'message' => 'Update successfull',
            'data'    => $data->toPublicArray(),
        ] );
    }

    public function destroy( $id ) {
        $data = WoocommerceCredential::where( 'vendor_id', vendorId() )->find( $id );
        if ( ! $data ) {
            return response()->json( ['status' => 404, 'message' => 'Not found'], 404 );
        }
        $data->delete();

        return $this->response( 'Deleted successfull' );
    }

    public function status( $id ) {
        $data = WoocommerceCredential::where( 'vendor_id', vendorId() )->find( $id );
        if ( ! $data ) {
            return response()->json( ['status' => 404, 'message' => 'Not found'], 404 );
        }

        try {
            $this->woo->validateConnection( $data->wc_url, $data->wc_key, $data->wc_secret );
            $data->update( [
                'status'             => WoocommerceCredential::STATUS_CONNECTED,
                'connection_message' => 'Connection OK',
                'last_error'         => null,
            ] );
        } catch ( \Throwable $e ) {
            $data->update( [
                'status'             => WoocommerceCredential::STATUS_ERROR,
                'connection_message' => $e->getMessage(),
                'last_error'         => $e->getMessage(),
            ] );
        }

        return response()->json( [
            'status' => 200,
            'data'   => $data->fresh()->toPublicArray(),
        ] );
    }

    public function default( $id ) {
        $data = WoocommerceCredential::where( 'vendor_id', vendorId() )->find( $id );
        if ( ! $data ) {
            return response()->json( ['status' => 404, 'message' => 'Not found'], 404 );
        }

        WoocommerceCredential::where( 'vendor_id', vendorId() )->update( ['is_active' => false] );
        $data->update( ['is_active' => true] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Default store updated.',
            'data'    => $data->fresh()->toPublicArray(),
        ] );
    }
}
