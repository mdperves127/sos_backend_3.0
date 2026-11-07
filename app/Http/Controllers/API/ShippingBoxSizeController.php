<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ShippingBoxSize;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ShippingBoxSizeController extends Controller
{
    public function index( Request $request )
    {
        $items = ShippingBoxSize::query()
            ->when( $request->query( 'status' ) === 'active', fn ( $q ) => $q->where( 'status', 'active' ) )
            ->latest()
            ->get();

        return response()->json( [
            'status'            => 200,
            'shipping_box_size' => $items,
        ] );
    }

    public function store( Request $request )
    {
        $this->normalizeAliases( $request );

        $validator = Validator::make( $request->all(), $this->rules() );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ], 400 );
        }

        $box = ShippingBoxSize::create( [
            'name'             => $request->input( 'name' ),
            'length'           => $request->input( 'length' ),
            'width'            => $request->input( 'width' ),
            'height'           => $request->input( 'height' ),
            'measurement_type' => $request->input( 'measurement_type', 'in' ),
            'weight'           => $request->input( 'weight' ),
            'weight_type'      => $request->input( 'weight_type', 'lb' ),
            'status'           => $request->input( 'status', 'active' ),
        ] );

        return response()->json( [
            'status'            => 200,
            'message'           => 'Shipping box size added successfully.',
            'shipping_box_size' => $box,
        ] );
    }

    public function edit( $id )
    {
        $box = ShippingBoxSize::find( $id );

        if ( ! $box ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Shipping Box Size Id Found',
            ], 404 );
        }

        return response()->json( [
            'status'            => 200,
            'shipping_box_size' => $box,
        ] );
    }

    public function update( Request $request, $id )
    {
        $box = ShippingBoxSize::find( $id );

        if ( ! $box ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Shipping Box Size Id Found',
            ], 404 );
        }

        $this->normalizeAliases( $request );

        $validator = Validator::make( $request->all(), $this->rules() );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ], 400 );
        }

        $box->name             = $request->input( 'name', $box->name );
        $box->length           = $request->input( 'length' );
        $box->width            = $request->input( 'width' );
        $box->height           = $request->input( 'height' );
        $box->measurement_type = $request->input( 'measurement_type', $box->measurement_type );
        $box->weight           = $request->input( 'weight' );
        $box->weight_type      = $request->input( 'weight_type', $box->weight_type );

        if ( $request->has( 'status' ) ) {
            $box->status = $request->input( 'status' );
        }

        $box->save();

        return response()->json( [
            'status'            => 200,
            'message'           => 'Shipping box size updated successfully.',
            'shipping_box_size' => $box,
        ] );
    }

    public function destroy( $id )
    {
        $box = ShippingBoxSize::find( $id );

        if ( ! $box ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Shipping Box Size Id Found',
            ], 404 );
        }

        $box->delete();

        return response()->json( [
            'status'  => 200,
            'message' => 'Shipping box size deleted successfully.',
        ] );
    }

    /**
     * Accept `mergerment_type` as an alias for `measurement_type`.
     */
    private function normalizeAliases( Request $request ): void
    {
        if ( ! $request->filled( 'measurement_type' ) && $request->filled( 'mergerment_type' ) ) {
            $request->merge( [
                'measurement_type' => $request->input( 'mergerment_type' ),
            ] );
        }
    }

    private function rules(): array
    {
        return [
            'name'             => ['nullable', 'string', 'max:255'],
            'length'           => ['required', 'numeric', 'min:0'],
            'width'            => ['required', 'numeric', 'min:0'],
            'height'           => ['required', 'numeric', 'min:0'],
            'measurement_type' => ['required', Rule::in( ['in', 'cm', 'mm', 'm', 'ft'] )],
            'weight'           => ['required', 'numeric', 'min:0'],
            'weight_type'      => ['required', Rule::in( ['lb', 'kg', 'g', 'oz'] )],
            'status'           => ['nullable', Rule::in( ['active', 'inactive'] )],
        ];
    }
}
