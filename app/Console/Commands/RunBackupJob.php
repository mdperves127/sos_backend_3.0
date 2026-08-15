<?php

namespace App\Console\Commands;

use App\Services\Admin\TenantBackupService;
use Illuminate\Console\Command;
use Throwable;

class RunBackupJob extends Command
{
    protected $signature = 'backup:run {jobId : Backup job UUID}';

    protected $description = 'Run a queued admin backup job in the background';

    public function handle( TenantBackupService $backups ): int
    {
        $jobId = (string) $this->argument( 'jobId' );

        try {
            $backups->runJob( $jobId );
            $this->info( "Backup job {$jobId} completed." );

            return self::SUCCESS;
        } catch ( Throwable $e ) {
            $this->error( $e->getMessage() );

            return self::FAILURE;
        }
    }
}
