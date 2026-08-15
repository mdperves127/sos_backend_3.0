<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\TenantBackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class BackupController extends Controller
{
    public function __construct( private TenantBackupService $backups )
    {
    }

    /**
     * Start an async backup job (avoids proxy/LiteSpeed 120s timeout).
     *
     * Query/body:
     * - include_central=1|0 (default 1)
     * - include_deleted=1|0 (default 0)
     * - include_assets=1|0 (default 0 — assets make backups much slower)
     */
    public function start( Request $request )
    {
        $includeCentral = filter_var( $request->input( 'include_central', true ), FILTER_VALIDATE_BOOLEAN );
        $includeDeleted = filter_var( $request->input( 'include_deleted', false ), FILTER_VALIDATE_BOOLEAN );
        $includeAssets  = filter_var( $request->input( 'include_assets', false ), FILTER_VALIDATE_BOOLEAN );

        $job = $this->backups->startJob( $includeCentral, $includeDeleted, $includeAssets );

        return response()->json( [
            'status'  => 200,
            'message' => 'Backup started. Poll status, then download when ready.',
            'data'    => [
                'job_id'       => $job['id'],
                'status'       => $job['status'],
                'message'      => $job['message'],
                'status_url'   => url( '/api/admin/backup/' . $job['id'] . '/status' ),
                'download_url' => url( '/api/admin/backup/' . $job['id'] . '/download' ),
            ],
        ] );
    }

    public function status( string $jobId )
    {
        $job = $this->backups->getJob( $jobId );

        if ( ! $job ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Backup job not found.',
            ], 404 );
        }

        return response()->json( [
            'status'  => 200,
            'message' => $job['message'] ?? $job['status'],
            'data'    => [
                'job_id'       => $job['id'],
                'status'       => $job['status'],
                'message'      => $job['message'] ?? null,
                'error'        => $job['error'] ?? null,
                'errors'       => $job['errors'] ?? [],
                'filename'     => $job['filename'] ?? null,
                'size'         => $job['size'] ?? null,
                'created_at'   => $job['created_at'] ?? null,
                'updated_at'   => $job['updated_at'] ?? null,
                'download_url' => ( $job['status'] ?? '' ) === 'ready'
                    ? url( '/api/admin/backup/' . $job['id'] . '/download' )
                    : null,
            ],
        ] );
    }

    public function downloadJob( string $jobId )
    {
        $job = $this->backups->getJob( $jobId );

        if ( ! $job ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Backup job not found.',
            ], 404 );
        }

        if ( ( $job['status'] ?? '' ) === 'failed' ) {
            return response()->json( [
                'status'  => 422,
                'message' => $job['message'] ?? 'Backup failed. Please start a new backup.',
                'data'    => [
                    'job_id' => $job['id'],
                    'error'  => $job['error'] ?? null,
                ],
            ], 422 );
        }

        if ( ( $job['status'] ?? '' ) !== 'ready' ) {
            return response()->json( [
                'status'  => 409,
                'message' => 'Backup is not ready yet.',
                'data'    => [
                    'job_id'  => $job['id'],
                    'status'  => $job['status'] ?? null,
                    'message' => $job['message'] ?? null,
                ],
            ], 409 );
        }

        $path = $job['path'] ?? null;
        if ( ! $path || ! File::exists( $path ) || File::size( $path ) < 1 ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Backup file missing. Please start a new backup.',
                'data'    => [
                    'job_id' => $job['id'],
                ],
            ], 404 );
        }

        $filename = $job['filename'] ?: ( $jobId . '.zip' );

        // Keep file so the same job can be downloaded again.
        return response()->download( $path, $filename, [
            'Content-Type' => 'application/zip',
        ] );
    }
}
