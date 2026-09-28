<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class MediaController extends Controller
{
    private const ACCEPTED_MIME = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'image/svg+xml',
        'application/pdf',
        'video/mp4',
    ];

    /**
     * GET /api/media
     * Query: page, limit|per_page, search, mimeType
     */
    public function index( Request $request )
    {
        $limit = (int) ( $request->input( 'limit', $request->input( 'per_page', 12 ) ) );
        $limit = max( 1, min( 100, $limit ) );

        $query = Media::query()
            ->when( $request->filled( 'search' ), function ( $q ) use ( $request ) {
                $search = $request->input( 'search' );
                $q->where( function ( $inner ) use ( $search ) {
                    $inner->where( 'original_name', 'like', '%' . $search . '%' )
                        ->orWhere( 'file_name', 'like', '%' . $search . '%' );
                } );
            } )
            ->when( $request->filled( 'mimeType' ), function ( $q ) use ( $request ) {
                $mime = (string) $request->input( 'mimeType' );
                if ( str_contains( $mime, '/' ) ) {
                    $q->where( 'mime_type', $mime );
                } else {
                    // e.g. "image" / "video"
                    $q->where( 'mime_type', 'like', $mime . '/%' );
                }
            } )
            ->latest( 'id' );

        $paginator = $query->paginate( $limit )->withQueryString();

        $items = collect( $paginator->items() )
            ->map( fn ( Media $media ) => $media->toApiArray() )
            ->values();

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'data'    => [
                'items' => $items,
                'meta'  => [
                    'page'       => $paginator->currentPage(),
                    'limit'      => $paginator->perPage(),
                    'total'      => $paginator->total(),
                    'totalPages' => $paginator->lastPage(),
                ],
            ],
            // Laravel shape also supported by the frontend normalizer
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'last_page'    => $paginator->lastPage(),
        ] );
    }

    /**
     * GET /api/media/{id}
     */
    public function show( $id )
    {
        $media = Media::find( $id );

        if ( ! $media ) {
            return response()->json( [
                'status'  => 404,
                'success' => false,
                'message' => 'Media not found.',
                'data'    => null,
            ], 404 );
        }

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'data'    => $media->toApiArray(),
        ] );
    }

    /**
     * POST /api/media/upload
     * Body: multipart file field "file"
     */
    public function upload( Request $request )
    {
        $validator = Validator::make( $request->all(), [
            'file' => [
                'required',
                'file',
                'max:51200', // 50 MB
                function ( $attribute, $value, $fail ) {
                    if ( ! $value ) {
                        return;
                    }
                    $mime = strtolower( (string) ( $value->getMimeType() ?: '' ) );
                    if ( ! in_array( $mime, self::ACCEPTED_MIME, true ) ) {
                        $fail( 'Unsupported file type. Allowed: jpeg, png, webp, svg, pdf, mp4.' );
                    }
                },
            ],
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
                'data'    => null,
            ], 400 );
        }

        $file = $request->file( 'file' );
        $originalName = $file->getClientOriginalName();
        $extension    = strtolower( $file->getClientOriginalExtension() ?: pathinfo( $originalName, PATHINFO_EXTENSION ) ?: 'bin' );
        $mimeType     = strtolower( (string) ( $file->getMimeType() ?: 'application/octet-stream' ) );
        $size         = (int) $file->getSize();

        $uploadDir  = Media::tenantUploadDirectory(); // uploads/media/{tenant_id}
        $storedPath = fileUpload( $file, $uploadDir );
        $fileName   = basename( $storedPath );

        $media = Media::create( [
            'user_id'       => Auth::id(),
            'file_name'     => $fileName,
            'original_name' => $originalName,
            'mime_type'     => $mimeType,
            'extension'     => $extension,
            'size'          => $size,
            'path'          => $storedPath,
        ] );

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'message' => 'Media uploaded successfully.',
            'data'    => $media->fresh()->toApiArray(),
        ], 201 );
    }

    /**
     * DELETE /api/media/{id}
     */
    public function destroy( $id )
    {
        $media = Media::find( $id );

        if ( ! $media ) {
            return response()->json( [
                'status'  => 404,
                'success' => false,
                'message' => 'Media not found.',
                'data'    => null,
            ], 404 );
        }

        $media->deleteFileFromDisk();
        $media->delete();

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'message' => 'Media deleted successfully.',
            'data'    => ['id' => (int) $id],
        ] );
    }
}
