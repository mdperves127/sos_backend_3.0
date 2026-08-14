<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\TenantBackupService;
use Illuminate\Http\Request;
use Throwable;

class BackupController extends Controller
{
    public function __construct( private TenantBackupService $backups )
    {
    }

    /**
     * Build full tenant DB + assets backup and stream it as a zip download.
     * Temp file is deleted after the response is sent (not stored).
     *
     * Optional query/body:
     * - include_central=0
     * - include_deleted=1
     */
    public function download( Request $request )
    {
        $includeCentral = filter_var( $request->input( 'include_central', true ), FILTER_VALIDATE_BOOLEAN );
        $includeDeleted = filter_var( $request->input( 'include_deleted', false ), FILTER_VALIDATE_BOOLEAN );

        try {
            $zip = $this->backups->createDownloadableZip( $includeCentral, $includeDeleted );
        } catch ( Throwable $e ) {
            return response()->json( [
                'status'  => 500,
                'message' => 'Backup failed: ' . $e->getMessage(),
            ], 500 );
        }

        return response()
            ->download( $zip['path'], $zip['filename'], [
                'Content-Type' => 'application/zip',
            ] )
            ->deleteFileAfterSend( true );
    }
}
