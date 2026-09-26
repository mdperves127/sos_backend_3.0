<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\PageBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PageBuilderController extends Controller
{
    /**
     * GET /api/page-builder
     */
    public function index( Request $request )
    {
        $limit = (int) ( $request->input( 'limit', $request->input( 'per_page', 12 ) ) );
        $limit = max( 1, min( 100, $limit ) );

        $query = PageBuilder::query()
            ->when( $request->filled( 'search' ), function ( $q ) use ( $request ) {
                $search = $request->input( 'search' );
                $q->where( function ( $inner ) use ( $search ) {
                    $inner->where( 'page_name', 'like', '%' . $search . '%' )
                        ->orWhere( 'slug', 'like', '%' . $search . '%' )
                        ->orWhere( 'path', 'like', '%' . $search . '%' );
                } );
            } )
            ->when(
                $request->filled( 'status' ) && $request->input( 'status' ) !== 'all',
                fn ( $q ) => $q->where( 'status', strtoupper( (string) $request->input( 'status' ) ) )
            )
            ->when(
                $request->filled( 'pageType' ) && $request->input( 'pageType' ) !== 'all',
                fn ( $q ) => $q->where( 'page_type', strtoupper( (string) $request->input( 'pageType' ) ) )
            )
            ->orderBy( 'sort_order' )
            ->orderByDesc( 'id' );

        $paginator = $query->paginate( $limit )->withQueryString();

        $items = collect( $paginator->items() )
            ->map( fn ( PageBuilder $page ) => $page->toApiArray() )
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
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'last_page'    => $paginator->lastPage(),
        ] );
    }

    /**
     * GET /api/page-builder/lookup?path=
     */
    public function lookup( Request $request )
    {
        $path = $this->normalizePath( (string) $request->query( 'path', '' ) );

        if ( $path === '' && $request->query( 'path' ) !== '/' && $request->query( 'path' ) !== '' ) {
            // allow empty after normalize only when path was "/" or ""
        }

        $page = PageBuilder::where( 'path', $path )->first();

        // Also try with leading slash variants.
        if ( ! $page && $path !== '' ) {
            $page = PageBuilder::where( 'path', '/' . ltrim( $path, '/' ) )->first();
        }

        if ( ! $page ) {
            return response()->json( [
                'status'  => 404,
                'success' => false,
                'message' => 'Page not found.',
                'data'    => null,
            ], 404 );
        }

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'data'    => $page->toApiArray(),
        ] );
    }

    /**
     * GET /api/page-builder/{id}
     */
    public function show( $id )
    {
        $page = PageBuilder::find( $id );

        if ( ! $page ) {
            return response()->json( [
                'status'  => 404,
                'success' => false,
                'message' => 'Page not found.',
                'data'    => null,
            ], 404 );
        }

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'data'    => $page->toApiArray(),
        ] );
    }

    /**
     * POST /api/page-builder
     */
    public function store( Request $request )
    {
        $validator = Validator::make( $request->all(), $this->rules() );

        if ( $validator->fails() ) {
            return $this->validationError( $validator->errors()->toArray() );
        }

        $data = $this->normalizePayload( $validator->validated() );

        if ( ( $data['status'] ?? 'DRAFT' ) === 'PUBLISHED' && empty( $data['published_at'] ) ) {
            $data['published_at'] = now();
        }

        $page = PageBuilder::create( $data );

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'message' => 'Page created successfully.',
            'data'    => $page->fresh()->toApiArray(),
        ], 201 );
    }

    /**
     * PATCH /api/page-builder/{id}
     * Status is not updated here (use updateStatus).
     */
    public function update( Request $request, $id )
    {
        $page = PageBuilder::find( $id );

        if ( ! $page ) {
            return response()->json( [
                'status'  => 404,
                'success' => false,
                'message' => 'Page not found.',
                'data'    => null,
            ], 404 );
        }

        $validator = Validator::make( $request->all(), $this->rules( $page->id, false ) );

        if ( $validator->fails() ) {
            return $this->validationError( $validator->errors()->toArray() );
        }

        $data = $this->normalizePayload( $validator->validated(), false );
        unset( $data['status'], $data['published_at'] );

        $page->update( $data );

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'message' => 'Page updated successfully.',
            'data'    => $page->fresh()->toApiArray(),
        ] );
    }

    /**
     * PATCH /api/page-builder/{id}/status
     */
    public function updateStatus( Request $request, $id )
    {
        $page = PageBuilder::find( $id );

        if ( ! $page ) {
            return response()->json( [
                'status'  => 404,
                'success' => false,
                'message' => 'Page not found.',
                'data'    => null,
            ], 404 );
        }

        $validator = Validator::make( $request->all(), [
            'status' => ['required', Rule::in( PageBuilder::STATUSES )],
        ] );

        if ( $validator->fails() ) {
            return $this->validationError( $validator->errors()->toArray() );
        }

        $status = strtoupper( (string) $request->input( 'status' ) );
        $page->status = $status;

        if ( $status === 'PUBLISHED' && ! $page->published_at ) {
            $page->published_at = now();
        }

        if ( $status === 'DRAFT' || $status === 'ARCHIVED' ) {
            // keep published_at history; do not clear
        }

        $page->save();

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'message' => 'Page status updated successfully.',
            'data'    => $page->fresh()->toApiArray(),
        ] );
    }

    /**
     * DELETE /api/page-builder/{id}
     */
    public function destroy( $id )
    {
        $page = PageBuilder::find( $id );

        if ( ! $page ) {
            return response()->json( [
                'status'  => 404,
                'success' => false,
                'message' => 'Page not found.',
                'data'    => null,
            ], 404 );
        }

        $page->delete();

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'message' => 'Page deleted successfully.',
            'data'    => ['id' => (string) $id],
        ] );
    }

    /**
     * POST /api/page-builder/{id}/duplicate
     */
    public function duplicate( $id )
    {
        $page = PageBuilder::find( $id );

        if ( ! $page ) {
            return response()->json( [
                'status'  => 404,
                'success' => false,
                'message' => 'Page not found.',
                'data'    => null,
            ], 404 );
        }

        $suffix = '-' . Str::lower( Str::random( 4 ) );
        $slug   = $this->uniqueSlug( $page->slug . '-copy' . $suffix );
        $path   = $this->uniquePath( rtrim( $page->path, '/' ) . '-copy' . $suffix );

        $copy = PageBuilder::create( [
            'path'         => $path,
            'slug'         => $slug,
            'page_name'    => $page->page_name . ' (Copy)',
            'page_type'    => $page->page_type,
            'template'     => $page->template,
            'status'       => 'DRAFT',
            'blocks'       => $page->blocks,
            'sort_order'   => (int) $page->sort_order,
            'published_at' => null,
            'seo'          => $page->seo,
            'settings'     => $page->settings,
        ] );

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'message' => 'Page duplicated successfully.',
            'data'    => $copy->toApiArray(),
        ], 201 );
    }

    /**
     * Public storefront: published page by path query (?path=).
     */
    public function publicLookup( Request $request )
    {
        $path = $this->normalizePath( (string) $request->query( 'path', '' ) );

        $page = PageBuilder::where( 'status', 'PUBLISHED' )
            ->where( function ( $q ) use ( $path ) {
                $q->where( 'path', $path )
                    ->orWhere( 'path', '/' . ltrim( $path, '/' ) );
            } )
            ->first();

        if ( ! $page ) {
            return response()->json( [
                'status'  => 404,
                'success' => false,
                'message' => 'Page not found.',
                'data'    => null,
            ], 404 );
        }

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'data'    => $page->toApiArray(),
        ] );
    }

    /**
     * Public storefront: published page by path.
     * GET /tenant-frontend/{slug}  (slug = page_builders.path)
     */
    public function publicShow( string $slug )
    {
        $path = $this->normalizePath( $slug );

        $page = PageBuilder::where( 'status', 'PUBLISHED' )
            ->where( function ( $q ) use ( $path ) {
                $q->where( 'path', $path )
                    ->orWhere( 'path', '/' . ltrim( $path, '/' ) );
            } )
            ->first();

        if ( ! $page ) {
            return response()->json( [
                'status'  => 404,
                'success' => false,
                'message' => 'Page not found.',
                'data'    => null,
            ], 404 );
        }

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'data'    => $page->toApiArray(),
        ] );
    }

    /**
     * @return array<string, mixed>
     */
    private function rules( ?int $ignoreId = null, bool $includeStatus = true ): array
    {
        $pathRule = Rule::unique( 'page_builders', 'path' );
        $slugRule = Rule::unique( 'page_builders', 'slug' );

        if ( $ignoreId ) {
            $pathRule = $pathRule->ignore( $ignoreId );
            $slugRule = $slugRule->ignore( $ignoreId );
        }

        $rules = [
            'path'      => ['required', 'string', 'max:255', $pathRule],
            'slug'      => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugRule],
            'pageName'  => ['required', 'string', 'max:255'],
            'pageType'  => ['required', Rule::in( PageBuilder::PAGE_TYPES )],
            'template'  => ['nullable', 'string', 'max:255'],
            'blocks'    => ['nullable', 'array'],
            'sortOrder' => ['nullable', 'integer', 'min:0'],
            'seo'       => ['nullable', 'array'],
            'seo.metaTitle'       => ['nullable', 'string', 'max:255'],
            'seo.metaDescription' => ['nullable', 'string', 'max:1000'],
            'seo.canonicalUrl'    => ['nullable', 'string', 'max:500'],
            'seo.noIndex'         => ['nullable', 'boolean'],
            'seo.noFollow'        => ['nullable', 'boolean'],
            'seo.ogTitle'         => ['nullable', 'string', 'max:255'],
            'seo.ogDescription'   => ['nullable', 'string', 'max:1000'],
            'seo.ogImage'         => ['nullable', 'string', 'max:500'],
            'seo.twitterTitle'    => ['nullable', 'string', 'max:255'],
            'seo.twitterDescription' => ['nullable', 'string', 'max:1000'],
            'seo.twitterImage'    => ['nullable', 'string', 'max:500'],
            'seo.focusKeyword'    => ['nullable', 'string', 'max:255'],
            'seo.structuredData'  => ['nullable', 'array'],
            'settings'            => ['nullable', 'array'],
            'settings.*.key'      => ['required_with:settings', 'string', 'max:255'],
            'settings.*.value'    => ['nullable'],
            'settings.*.label'    => ['nullable', 'string', 'max:255'],
            'settings.*.description' => ['nullable', 'string', 'max:1000'],
            'settings.*.group'    => ['nullable', 'string', 'max:255'],
            'settings.*.sortOrder'=> ['nullable', 'integer', 'min:0'],
        ];

        if ( $includeStatus ) {
            $rules['status'] = ['nullable', Rule::in( PageBuilder::STATUSES )];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizePayload( array $input, bool $includeStatus = true ): array
    {
        $data = [
            'path'       => $this->normalizePath( (string) ( $input['path'] ?? '' ) ),
            'slug'       => Str::slug( (string) ( $input['slug'] ?? '' ) ),
            'page_name'  => (string) ( $input['pageName'] ?? '' ),
            'page_type'  => strtoupper( (string) ( $input['pageType'] ?? 'STANDARD' ) ),
            'template'   => $input['template'] ?? null,
            'blocks'     => $input['blocks'] ?? [],
            'sort_order' => (int) ( $input['sortOrder'] ?? 0 ),
            'seo'        => $input['seo'] ?? null,
            'settings'   => $input['settings'] ?? null,
        ];

        if ( $includeStatus ) {
            $data['status'] = strtoupper( (string) ( $input['status'] ?? 'DRAFT' ) );
        }

        return $data;
    }

    private function normalizePath( string $path ): string
    {
        $path = trim( $path );
        if ( $path === '' || $path === '/' ) {
            return '/';
        }

        // Keep leading slash, drop trailing slash.
        $path = '/' . ltrim( $path, '/' );

        return rtrim( $path, '/' ) ?: '/';
    }

    private function uniqueSlug( string $base ): string
    {
        $slug = Str::slug( $base ) ?: 'page';
        $candidate = $slug;
        $i = 1;

        while ( PageBuilder::where( 'slug', $candidate )->exists() ) {
            $candidate = $slug . '-' . $i++;
        }

        return $candidate;
    }

    private function uniquePath( string $base ): string
    {
        $path = $this->normalizePath( $base );
        $candidate = $path;
        $i = 1;

        while ( PageBuilder::where( 'path', $candidate )->exists() ) {
            $candidate = $path . '-' . $i++;
        }

        return $candidate;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function validationError( array $errors )
    {
        return response()->json( [
            'status'  => 400,
            'success' => false,
            'message' => 'Validation error',
            'errors'  => $errors,
            'data'    => null,
        ], 400 );
    }
}
