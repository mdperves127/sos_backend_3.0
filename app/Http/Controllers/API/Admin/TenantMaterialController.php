<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TenantMaterialRequest;
use App\Models\TenantMaterial;
use Illuminate\Support\Facades\File;

class TenantMaterialController extends Controller
{
    public function index()
    {
        return $this->response( TenantMaterial::singleton() );
    }

    public function update( TenantMaterialRequest $request )
    {
        $material = TenantMaterial::singleton();
        $data     = [];

        if ( $request->hasFile( 'tenant_advertise_banner' ) ) {
            $this->deleteBanner( $material->tenant_advertise_banner );
            $path = fileUpload(
                $request->file( 'tenant_advertise_banner' ),
                'uploads/tenant-materials',
                1200,
                400
            );
            $data['tenant_advertise_banner']     = $path;
            $data['tenant_advertise_banner_url'] = asset( ltrim( $path, '/' ) );
        }

        if ( $request->filled( 'tenant_advertise_banner_url' ) ) {
            $data['tenant_advertise_banner_url'] = $request->input( 'tenant_advertise_banner_url' );
        } elseif ( $request->exists( 'tenant_advertise_banner_url' ) && $request->input( 'tenant_advertise_banner_url' ) === null ) {
            $data['tenant_advertise_banner_url'] = null;
        }

        foreach ( ['theme_one_url', 'theme_two_url', 'theme_three_url', 'theme_four_url'] as $field ) {
            if ( $request->filled( $field ) ) {
                $data[$field] = $request->input( $field );
            } elseif ( $request->exists( $field ) && $request->input( $field ) === null ) {
                $data[$field] = null;
            }
        }

        if ( $data !== [] ) {
            $material->update( $data );
        }

        return $this->response( [
            'message' => 'Tenant materials updated successfully.',
            'data'    => $material->fresh(),
        ] );
    }

    private function deleteBanner( ?string $banner ): void
    {
        if ( ! $banner ) {
            return;
        }

        $relativePath = ltrim( preg_replace( '#/{2,}#', '/', $banner ), '/' );
        $fullPath     = public_path( $relativePath );

        if ( File::exists( $fullPath ) ) {
            File::delete( $fullPath );
        }
    }
}
