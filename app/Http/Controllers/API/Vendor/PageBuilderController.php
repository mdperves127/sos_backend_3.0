<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\MerchantFrontendController;
use App\Models\PageBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        $data = $page->toApiArray();
        $data['blocks'] = $this->enrichBlocksWithApiData( $data['blocks'] ?? [] );

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'data'    => $data,
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

        $data = $page->toApiArray();
        $data['blocks'] = $this->enrichBlocksWithApiData( $data['blocks'] ?? [] );

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'data'    => $data,
        ] );
    }

    /**
     * Published shop listing page-builder payload (no block items).
     * Matches /shop, /shop-page, and other /shop* list paths (plus page_type SHOP).
     * Excludes shop-details pages.
     *
     * @return array<string, mixed>|null
     */
    public function resolvePublishedShopPageData(): ?array
    {
        $page = PageBuilder::where( 'status', 'PUBLISHED' )
            ->where( function ( $q ) {
                $q->where( 'page_type', 'SHOP' )
                    ->orWhere( 'path', '/shop' )
                    ->orWhere( 'path', 'shop' )
                    ->orWhere( 'path', '/shop-page' )
                    ->orWhere( 'path', 'shop-page' )
                    ->orWhere( 'path', 'like', '/shop/%' )
                    ->orWhere( 'path', 'like', '/shop-%' )
                    ->orWhere( 'path', 'like', 'shop/%' )
                    ->orWhere( 'path', 'like', 'shop-%' );
            } )
            ->where( function ( $q ) {
                $q->whereNull( 'page_type' )
                    ->orWhere( 'page_type', '!=', 'SHOP_DETAILS' );
            } )
            ->where( 'path', 'not like', '%shop-details%' )
            ->where( 'path', 'not like', '%shop_details%' )
            ->orderByRaw( "CASE
                WHEN path IN ('/shop', 'shop') THEN 0
                WHEN path IN ('/shop-page', 'shop-page') THEN 1
                WHEN page_type = 'SHOP' THEN 2
                ELSE 3
            END" )
            ->orderBy( 'sort_order' )
            ->orderByDesc( 'id' )
            ->first();

        if ( ! $page ) {
            return null;
        }

        return $page->toApiArray();
    }

    /**
     * Published shop-details page-builder payload (no block items).
     * Matches /shop-details and page_type SHOP_DETAILS.
     *
     * @return array<string, mixed>|null
     */
    public function resolvePublishedShopDetailsPageData(): ?array
    {
        $page = PageBuilder::where( 'status', 'PUBLISHED' )
            ->where( function ( $q ) {
                $q->where( 'page_type', 'SHOP_DETAILS' )
                    ->orWhere( 'path', '/shop-details' )
                    ->orWhere( 'path', 'shop-details' )
                    ->orWhere( 'path', 'like', '%shop-details%' )
                    ->orWhere( 'path', 'like', '%shop_details%' );
            } )
            ->orderByRaw( "CASE
                WHEN path IN ('/shop-details', 'shop-details') THEN 0
                WHEN page_type = 'SHOP_DETAILS' THEN 1
                ELSE 2
            END" )
            ->orderBy( 'sort_order' )
            ->orderByDesc( 'id' )
            ->first();

        if ( ! $page ) {
            return null;
        }

        return $page->toApiArray();
    }

    /**
     * Resolve block apiName endpoints and attach matching records as data.items.
     *
     * @param  array<int, mixed>  $blocks
     * @return array<int, mixed>
     */
    private function enrichBlocksWithApiData( array $blocks ): array
    {
        foreach ( $blocks as $index => $block ) {
            if ( ! is_array( $block ) ) {
                continue;
            }

            $data = $block['data'] ?? null;
            if ( ! is_array( $data ) ) {
                continue;
            }

            $apiName = $data['apiName'] ?? null;
            if ( ! is_string( $apiName ) || trim( $apiName ) === '' ) {
                continue;
            }

            $blocks[ $index ]['data']['items'] = $this->resolveApiNameData( $apiName, $data );
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $blockData
     * @return array<int, mixed>
     */
    private function resolveApiNameData( string $apiName, array $blockData ): array
    {
        $resource = $this->normalizeApiResource( $apiName );
        if ( $resource === null ) {
            return [];
        }

        if ( $resource === 'products' ) {
            return $this->resolveProductsData( $blockData );
        }

        $frontend = app( MerchantFrontendController::class );

        $response = match ( $resource ) {
            'categories'    => $frontend->categories(),
            'brands'        => $frontend->brands(),
            'subcategories' => $frontend->subcategories(),
            'colors'        => $frontend->colors(),
            'size', 'sizes' => $frontend->size(),
            default         => null,
        };

        if ( ! $response instanceof JsonResponse ) {
            return [];
        }

        $decoded = json_decode( $response->getContent(), true );
        $items   = collect( is_array( $decoded ) ? $decoded : [] );

        $selectedIds = $this->normalizeIdList( $blockData['selectedIds'] ?? [] );

        if ( $selectedIds->isNotEmpty() ) {
            $items = $this->pickItemsByIds( $items, $selectedIds );
        }

        $queryEnabled = (bool) ( $blockData['queryEnabled'] ?? false );
        $query        = is_array( $blockData['query'] ?? null ) ? $blockData['query'] : [];

        if ( $queryEnabled ) {
            $items = $this->applyBlockQueryFilters( $items, $query );
        }

        return $items->values()->all();
    }

    /**
     * Resolve tenant-frontend/products for product blocks.
     * Prefer productIds when set; otherwise filter by category/brand/subcategory + optional query.
     *
     * @param  array<string, mixed>  $blockData
     * @return array<int, mixed>
     */
    private function resolveProductsData( array $blockData ): array
    {
        $productIds = $this->normalizeIdList( $blockData['productIds'] ?? [] );
        if ( $productIds->isEmpty() ) {
            $productIds = $this->normalizeIdList( $blockData['selectedIds'] ?? [] );
        }

        $categoryIds    = $this->normalizeIdList( $blockData['categoryIds'] ?? [] );
        $brandIds       = $this->normalizeIdList( $blockData['brandIds'] ?? [] );
        $subcategoryIds = $this->normalizeIdList( $blockData['subcategoryIds'] ?? [] );
        $queryEnabled   = (bool) ( $blockData['queryEnabled'] ?? false );
        $query          = is_array( $blockData['query'] ?? null ) ? $blockData['query'] : [];

        $requestParams = [
            'page'  => 1,
            'limit' => 100000,
        ];

        // When picking explicit products, skip category narrowing so IDs are not dropped.
        if ( $productIds->isEmpty() && $categoryIds->isNotEmpty() ) {
            $requestParams['category_id'] = $categoryIds->implode( ',' );
        }

        $request = Request::create( '/tenant-frontend/products', 'GET', $requestParams );
        $request->attributes->set( 'page_builder_skip', true );
        $response = app( MerchantFrontendController::class )->products( $request );

        if ( ! $response instanceof JsonResponse ) {
            return [];
        }

        $decoded = json_decode( $response->getContent(), true );
        $items   = collect( is_array( $decoded['data'] ?? null ) ? $decoded['data'] : [] );

        if ( $productIds->isNotEmpty() ) {
            $items = $this->pickItemsByIds( $items, $productIds );

            if ( ! $queryEnabled ) {
                return $this->projectNeededFields( $items, $blockData['needFields'] ?? null );
            }
        } else {
            if ( $brandIds->isNotEmpty() ) {
                $items = $items->filter( function ( $item ) use ( $brandIds ) {
                    if ( ! is_array( $item ) ) {
                        return false;
                    }

                    $candidates = [
                        (string) ( $item['brand_id'] ?? '' ),
                        (string) ( $item['market_place_brand_id'] ?? '' ),
                    ];

                    return $brandIds->intersect( $candidates )->isNotEmpty();
                } )->values();
            }

            if ( $subcategoryIds->isNotEmpty() ) {
                $items = $items->filter( function ( $item ) use ( $subcategoryIds ) {
                    if ( ! is_array( $item ) ) {
                        return false;
                    }

                    $candidates = [
                        (string) ( $item['subcategory_id'] ?? '' ),
                        (string) ( $item['sub_category_id'] ?? '' ),
                        (string) ( $item['market_place_subcategory_id'] ?? '' ),
                    ];

                    return $subcategoryIds->intersect( $candidates )->isNotEmpty();
                } )->values();
            }
        }

        if ( $queryEnabled ) {
            $items = $this->applyBlockQueryFilters( $items, $query );
        }

        return $this->projectNeededFields( $items, $blockData['needFields'] ?? null );
    }

    /**
     * Keep only the fields listed in needFields (plus id when available).
     *
     * @param  Collection<int, mixed>  $items
     * @param  mixed                   $needFields
     * @return array<int, mixed>
     */
    private function projectNeededFields( Collection $items, $needFields ): array
    {
        $fields = collect( is_array( $needFields ) ? $needFields : [] )
            ->filter( fn ( $field ) => is_string( $field ) && trim( $field ) !== '' )
            ->map( fn ( string $field ) => trim( $field ) )
            ->unique()
            ->values();

        if ( $fields->isEmpty() ) {
            return $items->values()->all();
        }

        return $items->map( function ( $item ) use ( $fields ) {
            if ( ! is_array( $item ) ) {
                return $item;
            }

            $projected = [];
            foreach ( $fields as $field ) {
                $projected[ $field ] = $item[ $field ] ?? null;
            }

            return $projected;
        } )->values()->all();
    }

    private function normalizeApiResource( string $apiName ): ?string
    {
        $normalized = strtolower( trim( $apiName ) );
        $normalized = preg_replace( '#^/+#', '', $normalized ) ?? $normalized;
        $normalized = preg_replace( '#^api/+#', '', $normalized ) ?? $normalized;

        if ( str_starts_with( $normalized, 'tenant-frontend/' ) ) {
            $normalized = substr( $normalized, strlen( 'tenant-frontend/' ) );
        }

        $normalized = trim( $normalized, '/' );

        $allowed = [
            'products',
            'categories',
            'brands',
            'subcategories',
            'colors',
            'size',
            'sizes',
        ];

        return in_array( $normalized, $allowed, true ) ? $normalized : null;
    }

    /**
     * @param  mixed  $ids
     * @return Collection<int, string>
     */
    private function normalizeIdList( $ids ): Collection
    {
        return collect( is_array( $ids ) ? $ids : [] )
            ->filter( fn ( $id ) => $id !== null && $id !== '' )
            ->map( fn ( $id ) => (string) $id )
            ->values();
    }

    /**
     * @param  Collection<int, mixed>  $items
     * @param  Collection<int, string> $ids
     * @return Collection<int, mixed>
     */
    private function pickItemsByIds( Collection $items, Collection $ids ): Collection
    {
        $byId = $items->keyBy( fn ( $item ) => (string) ( is_array( $item ) ? ( $item['id'] ?? '' ) : '' ) );

        return $ids
            ->map( fn ( string $id ) => $byId->get( $id ) )
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, mixed>  $items
     * @param  array<string, mixed>    $query
     * @return Collection<int, mixed>
     */
    private function applyBlockQueryFilters( Collection $items, array $query ): Collection
    {
        $search = isset( $query['search'] ) && is_string( $query['search'] )
            ? trim( $query['search'] )
            : '';

        if ( $search !== '' ) {
            $needle = Str::lower( $search );
            $items  = $items->filter( function ( $item ) use ( $needle ) {
                if ( ! is_array( $item ) ) {
                    return false;
                }

                $haystacks = [
                    $item['name'] ?? null,
                    $item['slug'] ?? null,
                    $item['heading'] ?? null,
                    $item['title'] ?? null,
                    $item['uniqid'] ?? null,
                ];

                foreach ( $haystacks as $value ) {
                    if ( is_string( $value ) && str_contains( Str::lower( $value ), $needle ) ) {
                        return true;
                    }
                }

                return false;
            } )->values();
        }

        $status = isset( $query['status'] ) ? strtolower( (string) $query['status'] ) : 'all';
        if ( $status !== '' && $status !== 'all' ) {
            $items = $items->filter( function ( $item ) use ( $status ) {
                if ( ! is_array( $item ) ) {
                    return false;
                }

                return strtolower( (string) ( $item['status'] ?? '' ) ) === $status;
            } )->values();
        }

        $featured = isset( $query['featured'] ) ? strtolower( (string) $query['featured'] ) : 'all';
        if ( $featured !== '' && $featured !== 'all' ) {
            $wantFeatured = in_array( $featured, [ '1', 'true', 'yes', 'featured' ], true );
            $items        = $items->filter( function ( $item ) use ( $wantFeatured ) {
                if ( ! is_array( $item ) ) {
                    return false;
                }

                $value = $item['featured'] ?? $item['is_feature'] ?? $item['is_featured'] ?? null;
                if ( $value === null ) {
                    return ! $wantFeatured;
                }

                $isFeatured = $value === true
                    || $value === 1
                    || $value === '1'
                    || $value === 'true'
                    || $value === 'yes';

                return $wantFeatured ? $isFeatured : ! $isFeatured;
            } )->values();
        }

        $page  = max( 1, (int) ( $query['page'] ?? 1 ) );
        $limit = (int) ( $query['limit'] ?? 0 );

        if ( $limit > 0 ) {
            $items = $items->slice( ( $page - 1 ) * $limit, $limit )->values();
        }

        return $items;
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
