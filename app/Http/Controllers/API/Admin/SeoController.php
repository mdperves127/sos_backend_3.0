<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SeoController extends Controller
{
    public function index()
    {
        if ( checkpermission( 'seo' ) != 1 ) {
            return $this->permissionmessage();
        }

        $seos = Seo::latest()->get();

        return response()->json( [
            'status' => 200,
            'data'   => $seos,
        ] );
    }

    public function store( Request $request )
    {
        $validator = Validator::make( $request->all(), [
            'page_url'   => 'required|string|max:255',
            'seo_title'  => 'required|string|max:255',
            'seo_value'  => 'nullable|string',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        }

        Seo::create( $request->only( [ 'page_url', 'seo_title', 'seo_value' ] ) );

        return response()->json( [
            'status'  => 200,
            'message' => 'SEO data inserted successfully.',
        ] );
    }

    public function show( $id )
    {
        $seo = Seo::find( $id );

        if ( ! $seo ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No SEO data found.',
            ], 404 );
        }

        return response()->json( [
            'status' => 200,
            'datas'  => $seo,
        ] );
    }

    public function update( Request $request, $id )
    {
        $seo = Seo::find( $id );

        if ( ! $seo ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No SEO data found.',
            ], 404 );
        }

        $validator = Validator::make( $request->all(), [
            'page_url'  => 'required|string|max:255',
            'seo_title' => 'required|string|max:255',
            'seo_value' => 'nullable|string',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        }

        $seo->update( $request->only( [ 'page_url', 'seo_title', 'seo_value' ] ) );

        return response()->json( [
            'status'  => 200,
            'message' => 'SEO data updated successfully.',
        ] );
    }

    public function destroy( $id )
    {
        $seo = Seo::find( $id );

        if ( ! $seo ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No SEO data found.',
            ], 404 );
        }

        $seo->delete();

        return response()->json( [
            'status'  => 200,
            'message' => 'SEO data deleted successfully.',
        ] );
    }
}
