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
            $data['tenant_advertise_banner'] = fileUpload(
                $request->file( 'tenant_advertise_banner' ),
                'uploads/tenant-materials',
                1200,
                400
            );
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
