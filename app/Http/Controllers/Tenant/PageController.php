<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PageController extends Controller
{
    public function index()
    {
        $pages = Page::latest()->get();

        return response()->json( [
            'status' => 200,
            'data'   => $pages,
        ] );
    }

    public function store( Request $request )
    {
        $validator = Validator::make( $request->all(), [
            'page_name'    => 'required|string|max:255',
            'page_url'     => 'required|string|max:255|unique:pages,page_url',
            'page_content' => 'required|string',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        }

        $page = Page::create( [
            'page_name'    => $request->page_name,
            'page_url'     => $this->normalizePageUrl( $request->page_url ),
            'page_content' => $request->page_content,
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Page created successfully',
            'data'    => $page,
        ] );
    }

    public function edit( $id )
    {
        $page = Page::find( $id );

        if ( ! $page ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Page not found',
            ], 404 );
        }

        return response()->json( [
            'status' => 200,
            'data'   => $page,
        ] );
    }

    public function update( Request $request, $id )
    {
        $page = Page::find( $id );

        if ( ! $page ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Page not found',
            ], 404 );
        }

        $validator = Validator::make( $request->all(), [
            'page_name'    => 'required|string|max:255',
            'page_url'     => [
                'required',
                'string',
                'max:255',
                Rule::unique( 'pages', 'page_url' )->ignore( $page->id ),
            ],
            'page_content' => 'required|string',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        }

        $page->update( [
            'page_name'    => $request->page_name,
            'page_url'     => $this->normalizePageUrl( $request->page_url ),
            'page_content' => $request->page_content,
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Page updated successfully',
            'data'    => $page,
        ] );
    }

    public function destroy( $id )
    {
        $page = Page::find( $id );

        if ( ! $page ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Page not found',
            ], 404 );
        }

        $page->delete();

        return response()->json( [
            'status'  => 200,
            'message' => 'Page deleted successfully',
        ] );
    }

    public function showByUrl( $url )
    {
        $page = Page::where( 'page_url', $this->normalizePageUrl( $url ) )->first();

        if ( ! $page ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Page not found',
            ], 404 );
        }

        return response()->json( [
            'status' => 200,
            'data'   => $page,
        ] );
    }

    private function normalizePageUrl( string $url ): string
    {
        return trim( $url, " \t\n\r\0\x0B/" );
    }
}
