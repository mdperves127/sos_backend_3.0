<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddonRequest;
use App\Models\Addon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class AddonController extends Controller
{
    private const PHOTO_PATH = 'uploads/addons';

    public function index( Request $request ): JsonResponse
    {
        $query = Addon::query()->latest();

        if ( $request->filled( 'addon_type' ) ) {
            $query->where( 'addon_type', $request->input( 'addon_type' ) );
        }

        if ( $request->filled( 'for_tenant' ) ) {
            $query->where( 'for_tenant', $request->input( 'for_tenant' ) );
        }

        if ( $request->filled( 'type' ) ) {
            $query->where( 'type', $request->input( 'type' ) );
        }

        $perPage = min( max( (int) $request->input( 'per_page', 10 ), 1 ), 100 );

        return response()->json( [
            'status' => 200,
            'data'   => $query->paginate( $perPage ),
        ] );
    }

    public function store( AddonRequest $request ): JsonResponse
    {
        $data = $request->safe()->except( ['photo'] );

        if ( $request->hasFile( 'photo' ) ) {
            $data['photo'] = fileUpload( $request->file( 'photo' ), self::PHOTO_PATH );
        }

        Addon::create( $data );

        return response()->json( [
            'status'  => 200,
            'message' => 'Addon created successfully.',
        ] );
    }

    public function show( Addon $addon ): JsonResponse
    {
        return response()->json( [
            'status' => 200,
            'data'   => $addon,
        ] );
    }

    public function update( AddonRequest $request, Addon $addon ): JsonResponse
    {
        $data = $request->safe()->except( ['photo'] );

        if ( $request->hasFile( 'photo' ) ) {
            $this->deletePhoto( $addon->photo );
            $data['photo'] = fileUpload( $request->file( 'photo' ), self::PHOTO_PATH );
        }

        $addon->update( $data );

        return response()->json( [
            'status'  => 200,
            'message' => 'Addon updated successfully.',
        ] );
    }

    public function destroy( Addon $addon ): JsonResponse
    {
        $this->deletePhoto( $addon->photo );
        $addon->delete();

        return response()->json( [
            'status'  => 200,
            'message' => 'Addon deleted successfully.',
        ] );
    }

    private function deletePhoto( ?string $photo ): void
    {
        if ( ! $photo ) {
            return;
        }

        $path = public_path( $photo );

        if ( File::exists( $path ) ) {
            File::delete( $path );
        }
    }
}
