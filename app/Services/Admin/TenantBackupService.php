<?php

namespace App\Services\Admin;

use App\Models\Tenant;
use App\Services\CrossTenantQueryService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class TenantBackupService
{
    public function jobsDir(): string
    {
        $dir = storage_path( 'app/backups/jobs' );
        if ( ! File::isDirectory( $dir ) ) {
            File::makeDirectory( $dir, 0755, true );
        }

        return $dir;
    }

    public function zipsDir(): string
    {
        $dir = storage_path( 'app/backups/zips' );
        if ( ! File::isDirectory( $dir ) ) {
            File::makeDirectory( $dir, 0755, true );
        }

        return $dir;
    }

    public function startJob(
        bool $includeCentral = true,
        bool $includeDeleted = false,
        bool $includeAssets = false
    ): array {
        $jobId = (string) Str::uuid();
        $meta  = [
            'id'              => $jobId,
            'status'          => 'queued',
            'message'         => 'Backup queued.',
            'include_central' => $includeCentral,
            'include_deleted' => $includeDeleted,
            'include_assets'  => $includeAssets,
            'created_at'      => now()->toIso8601String(),
            'updated_at'      => now()->toIso8601String(),
            'filename'        => null,
            'path'            => null,
            'error'           => null,
            'errors'          => [],
        ];

        $this->writeJob( $jobId, $meta );
        $this->spawnJobProcess( $jobId );

        return $meta;
    }

    public function getJob( string $jobId ): ?array
    {
        $file = $this->jobsDir() . DIRECTORY_SEPARATOR . $jobId . '.json';
        if ( ! File::exists( $file ) ) {
            return null;
        }

        $data = json_decode( File::get( $file ), true );

        return is_array( $data ) ? $data : null;
    }

    public function runJob( string $jobId ): void
    {
        $meta = $this->getJob( $jobId );
        if ( ! $meta ) {
            throw new RuntimeException( "Backup job [{$jobId}] not found." );
        }

        $this->writeJob( $jobId, array_merge( $meta, [
            'status'     => 'processing',
            'message'    => 'Backup running…',
            'updated_at' => now()->toIso8601String(),
        ] ) );

        try {
            $zip = $this->createDownloadableZip(
                (bool) ( $meta['include_central'] ?? true ),
                (bool) ( $meta['include_deleted'] ?? false ),
                (bool) ( $meta['include_assets'] ?? false ),
                $jobId
            );

            $finalPath = $this->zipsDir() . DIRECTORY_SEPARATOR . $jobId . '.zip';
            if ( File::exists( $finalPath ) ) {
                File::delete( $finalPath );
            }
            File::move( $zip['path'], $finalPath );

            $this->writeJob( $jobId, array_merge( $meta, [
                'status'     => 'ready',
                'message'    => 'Backup ready for download.',
                'filename'   => $zip['filename'],
                'path'       => $finalPath,
                'updated_at' => now()->toIso8601String(),
                'errors'     => $zip['errors'] ?? [],
            ] ) );
        } catch ( Throwable $e ) {
            Log::error( 'Backup job failed', [
                'job_id' => $jobId,
                'error'  => $e->getMessage(),
            ] );

            $this->writeJob( $jobId, array_merge( $meta, [
                'status'     => 'failed',
                'message'    => 'Backup failed.',
                'error'      => $e->getMessage(),
                'updated_at' => now()->toIso8601String(),
            ] ) );

            throw $e;
        }
    }

    /**
     * @return array{path:string,filename:string,errors?:array}
     */
    public function createDownloadableZip(
        bool $includeCentral = true,
        bool $includeDeleted = false,
        bool $includeAssets = false,
        ?string $jobId = null
    ): array {
        @set_time_limit( 0 );
        @ini_set( 'max_execution_time', '0' );
        @ini_set( 'memory_limit', '1024M' );
        ignore_user_abort( true );

        $stamp    = now()->format( 'Y-m-d-His' );
        $basename = 'tenant-backup-' . $stamp;
        $workDir  = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'tmp-' . $basename;
        $zipPath  = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $basename . '.zip';
        $errors   = [];
        $databases = [];
        $assets   = [];

        File::deleteDirectory( $workDir );
        if ( File::exists( $zipPath ) ) {
            File::delete( $zipPath );
        }

        File::makeDirectory( $workDir . '/databases', 0755, true );
        if ( $includeAssets ) {
            File::makeDirectory( $workDir . '/assets', 0755, true );
        }

        try {
            if ( $jobId ) {
                $this->touchJob( $jobId, 'Dumping databases…' );
            }

            $tenantQuery = Tenant::on( 'mysql' )->with( 'domains' );
            if ( ! $includeDeleted ) {
                $tenantQuery->whereNull( 'deleted_at' );
            } else {
                $tenantQuery->withTrashed();
            }
            $tenants = $tenantQuery->get();

            if ( $includeCentral ) {
                $centralName = (string) config( 'database.connections.mysql.database' );
                $centralFile = $workDir . '/databases/central_' . $this->safeName( $centralName ) . '.sql';
                try {
                    $this->dumpMysqlDatabase( 'mysql', $centralName, $centralFile );
                    $databases[] = [
                        'type'     => 'central',
                        'database' => $centralName,
                        'file'     => 'databases/' . basename( $centralFile ),
                    ];
                } catch ( Throwable $e ) {
                    $errors[] = 'Central DB dump failed: ' . $e->getMessage();
                    Log::error( 'Backup central dump failed', ['error' => $e->getMessage()] );
                }
            }

            $i = 0;
            $total = $tenants->count();
            foreach ( $tenants as $tenant ) {
                $i++;
                if ( $jobId && ( $i === 1 || $i % 5 === 0 || $i === $total ) ) {
                    $this->touchJob( $jobId, "Dumping tenant databases ({$i}/{$total})…" );
                }

                $dbName  = CrossTenantQueryService::getDatabaseName( $tenant );
                $sqlFile = $workDir . '/databases/' . $this->safeName( $dbName ) . '.sql';

                try {
                    $this->dumpMysqlDatabase( 'mysql', $dbName, $sqlFile );
                    $databases[] = [
                        'type'      => 'tenant',
                        'tenant_id' => $tenant->id,
                        'database'  => $dbName,
                        'file'      => 'databases/' . basename( $sqlFile ),
                    ];
                } catch ( Throwable $e ) {
                    $errors[] = "Tenant {$tenant->id} ({$dbName}): " . $e->getMessage();
                    Log::warning( 'Backup tenant dump failed', [
                        'tenant_id' => $tenant->id,
                        'database'  => $dbName,
                        'error'     => $e->getMessage(),
                    ] );
                }
            }

            if ( $includeAssets ) {
                if ( $jobId ) {
                    $this->touchJob( $jobId, 'Copying assets…' );
                }

                $assetSources = [
                    'uploads'       => public_path( 'uploads' ),
                    'theme-content' => public_path( 'theme-content' ),
                ];

                foreach ( File::glob( storage_path( 'tenant*' ) ) ?: [] as $tenantStorage ) {
                    if ( is_dir( $tenantStorage ) ) {
                        $assetSources[basename( $tenantStorage )] = $tenantStorage;
                    }
                }

                foreach ( $assetSources as $label => $source ) {
                    if ( ! is_dir( $source ) ) {
                        continue;
                    }

                    $target = $workDir . '/assets/' . $label;
                    File::copyDirectory( $source, $target );
                    $assets[] = [
                        'name' => $label,
                        'path' => 'assets/' . $label,
                    ];
                }
            }

            File::put(
                $workDir . '/manifest.json',
                json_encode( [
                    'created_at'      => now()->toIso8601String(),
                    'app_url'         => config( 'app.url' ),
                    'include_central' => $includeCentral,
                    'include_deleted' => $includeDeleted,
                    'include_assets'  => $includeAssets,
                    'tenant_count'    => $tenants->count(),
                    'databases'       => $databases,
                    'assets'          => $assets,
                    'errors'          => $errors,
                    'tenants'         => $tenants->map( fn ( $t ) => [
                        'id'           => $t->id,
                        'company_name' => $t->company_name,
                        'type'         => $t->type,
                        'domain'       => optional( $t->domains->first() )->domain,
                        'database'     => CrossTenantQueryService::getDatabaseName( $t ),
                    ] )->values()->all(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
            );

            if ( $jobId ) {
                $this->touchJob( $jobId, 'Creating zip archive…' );
            }

            $this->zipDirectory( $workDir, $zipPath );

            if ( ! File::exists( $zipPath ) ) {
                throw new RuntimeException( 'Failed to create backup zip.' );
            }

            return [
                'path'     => $zipPath,
                'filename' => basename( $zipPath ),
                'errors'   => $errors,
            ];
        } catch ( Throwable $e ) {
            if ( File::exists( $zipPath ) ) {
                File::delete( $zipPath );
            }
            throw $e;
        } finally {
            if ( File::isDirectory( $workDir ) ) {
                File::deleteDirectory( $workDir );
            }
        }
    }

    private function spawnJobProcess( string $jobId ): void
    {
        // Prefer a detached CLI process when shell functions are allowed.
        if ( $this->spawnDetachedCliProcess( $jobId ) ) {
            return;
        }

        // Shared hosts often disable exec()/shell_exec(). Run after the HTTP
        // response is sent so the API still returns immediately (no proxy timeout).
        dispatch( function () use ( $jobId ) {
            ignore_user_abort( true );
            @set_time_limit( 0 );
            @ini_set( 'max_execution_time', '0' );

            try {
                app( self::class )->runJob( $jobId );
            } catch ( Throwable $e ) {
                Log::error( 'Backup afterResponse job failed', [
                    'job_id' => $jobId,
                    'error'  => $e->getMessage(),
                ] );
            }
        } )->afterResponse();
    }

    private function spawnDetachedCliProcess( string $jobId ): bool
    {
        $php     = $this->findPhpBinary();
        $artisan = base_path( 'artisan' );
        $logFile = storage_path( 'logs/backup-' . $jobId . '.log' );

        if ( PHP_OS_FAMILY === 'Windows' ) {
            if ( ! \function_exists( 'popen' ) || ! \function_exists( 'pclose' ) ) {
                return false;
            }

            $command = sprintf(
                'start /B "" %s %s backup:run %s > %s 2>&1',
                \escapeshellarg( $php ),
                \escapeshellarg( $artisan ),
                \escapeshellarg( $jobId ),
                \escapeshellarg( $logFile )
            );

            $handle = @\popen( $command, 'r' );
            if ( $handle === false ) {
                return false;
            }
            \pclose( $handle );

            return true;
        }

        if ( ! \function_exists( 'exec' ) ) {
            return false;
        }

        $command = sprintf(
            'nohup %s %s backup:run %s > %s 2>&1 &',
            \escapeshellarg( $php ),
            \escapeshellarg( $artisan ),
            \escapeshellarg( $jobId ),
            \escapeshellarg( $logFile )
        );

        try {
            @\exec( $command );
        } catch ( Throwable $e ) {
            Log::warning( 'Backup CLI spawn failed', ['error' => $e->getMessage()] );

            return false;
        }

        return true;
    }

    private function findPhpBinary(): string
    {
        $configured = env( 'PHP_BINARY_PATH' );
        if ( $configured && File::exists( $configured ) ) {
            return $configured;
        }

        if ( defined( 'PHP_BINARY' ) && PHP_BINARY && File::exists( PHP_BINARY ) ) {
            // Avoid php-fpm/cli mismatch names that can't run artisan
            $base = strtolower( basename( PHP_BINARY ) );
            if ( ! str_contains( $base, 'fpm' ) && ! str_contains( $base, 'cgi' ) ) {
                return PHP_BINARY;
            }
        }

        return 'php';
    }

    private function writeJob( string $jobId, array $meta ): void
    {
        File::put(
            $this->jobsDir() . DIRECTORY_SEPARATOR . $jobId . '.json',
            json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
        );
    }

    private function touchJob( string $jobId, string $message ): void
    {
        $meta = $this->getJob( $jobId );
        if ( ! $meta ) {
            return;
        }

        $meta['message']    = $message;
        $meta['updated_at'] = now()->toIso8601String();
        $this->writeJob( $jobId, $meta );
    }

    private function dumpMysqlDatabase( string $connection, string $database, string $outputFile ): void
    {
        $config = config( 'database.connections.' . $connection );

        if ( ! is_array( $config ) ) {
            throw new RuntimeException( "Unknown DB connection [{$connection}]." );
        }

        $host = (string) ( $config['host'] ?? '127.0.0.1' );
        $port = (string) ( $config['port'] ?? '3306' );
        $user = (string) ( $config['username'] ?? '' );
        $pass = (string) ( $config['password'] ?? '' );

        if ( $this->dumpWithMysqldump( $host, $port, $user, $pass, $database, $outputFile ) ) {
            return;
        }

        $this->dumpWithPhp( $host, $port, $user, $pass, $database, $outputFile );
    }

    private function dumpWithMysqldump(
        string $host,
        string $port,
        string $user,
        string $pass,
        string $database,
        string $outputFile
    ): bool {
        $binary = $this->findMysqldumpBinary();
        if ( ! $binary ) {
            return false;
        }

        $cnf = tempnam( sys_get_temp_dir(), 'mysqldump' );
        if ( $cnf === false ) {
            return false;
        }

        $cnfContent = "[client]\n"
            . 'user=' . $this->escapeCnfValue( $user ) . "\n"
            . 'password=' . $this->escapeCnfValue( $pass ) . "\n"
            . 'host=' . $this->escapeCnfValue( $host ) . "\n"
            . 'port=' . $this->escapeCnfValue( $port ) . "\n";

        file_put_contents( $cnf, $cnfContent );

        try {
            $command = sprintf(
                '%s --defaults-extra-file=%s --single-transaction --quick --routines --triggers --databases %s > %s 2>&1',
                escapeshellarg( $binary ),
                escapeshellarg( $cnf ),
                escapeshellarg( $database ),
                escapeshellarg( $outputFile )
            );

            $output = [];
            $code   = 0;
            if ( ! \function_exists( 'exec' ) ) {
                return false;
            }
            @\exec( $command, $output, $code );

            if ( $code !== 0 || ! File::exists( $outputFile ) || File::size( $outputFile ) === 0 ) {
                Log::warning( 'mysqldump failed, will try PHP dump', [
                    'database' => $database,
                    'code'     => $code,
                    'output'   => implode( "\n", $output ),
                ] );

                return false;
            }

            return true;
        } finally {
            @unlink( $cnf );
        }
    }

    private function dumpWithPhp(
        string $host,
        string $port,
        string $user,
        string $pass,
        string $database,
        string $outputFile
    ): void {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $pdo = new \PDO( $dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE                  => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE       => \PDO::FETCH_ASSOC,
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ] );

        $handle = fopen( $outputFile, 'wb' );
        if ( $handle === false ) {
            throw new RuntimeException( "Unable to write dump file for {$database}." );
        }

        try {
            fwrite( $handle, "-- Backup dump for `{$database}`\n" );
            fwrite( $handle, '-- Generated at ' . now()->toDateTimeString() . "\n\n" );
            fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n\n" );
            fwrite( $handle, "CREATE DATABASE IF NOT EXISTS `{$database}`;\nUSE `{$database}`;\n\n" );

            $tables = $pdo->query( 'SHOW TABLES' )->fetchAll( \PDO::FETCH_COLUMN );

            foreach ( $tables as $table ) {
                $safeTable = str_replace( '`', '``', $table );
                $create    = $pdo->query( 'SHOW CREATE TABLE `' . $safeTable . '`' )->fetch();
                $createSql = $create['Create Table'] ?? $create['Create View'] ?? null;

                if ( ! $createSql ) {
                    continue;
                }

                fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
                fwrite( $handle, $createSql . ";\n\n" );

                $stmt   = $pdo->query( 'SELECT * FROM `' . $safeTable . '`' );
                $batch  = [];
                $colsSql = null;
                $batchSize = 100;

                while ( $row = $stmt->fetch() ) {
                    if ( $colsSql === null ) {
                        $colsSql = implode( ', ', array_map(
                            fn ( $col ) => '`' . str_replace( '`', '``', $col ) . '`',
                            array_keys( $row )
                        ) );
                    }

                    $values = array_map( function ( $value ) use ( $pdo ) {
                        if ( $value === null ) {
                            return 'NULL';
                        }

                        return $pdo->quote( (string) $value );
                    }, array_values( $row ) );

                    $batch[] = '(' . implode( ', ', $values ) . ')';

                    if ( count( $batch ) >= $batchSize ) {
                        fwrite( $handle, "INSERT INTO `{$table}` ({$colsSql}) VALUES\n" . implode( ",\n", $batch ) . ";\n" );
                        $batch = [];
                    }
                }

                if ( $batch !== [] && $colsSql !== null ) {
                    fwrite( $handle, "INSERT INTO `{$table}` ({$colsSql}) VALUES\n" . implode( ",\n", $batch ) . ";\n" );
                }

                fwrite( $handle, "\n" );
            }

            fwrite( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );
        } finally {
            fclose( $handle );
        }
    }

    private function findMysqldumpBinary(): ?string
    {
        $candidates = array_filter( [
            env( 'MYSQLDUMP_PATH' ),
            'mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/opt/homebrew/bin/mysqldump',
        ] );

        foreach ( $candidates as $candidate ) {
            if ( $candidate === 'mysqldump' ) {
                $which = stripos( PHP_OS_FAMILY, 'Windows' ) === 0 ? 'where' : 'command -v';
                $found = '';
                if ( \function_exists( 'shell_exec' ) ) {
                    $found = trim( (string) @\shell_exec( $which . ' mysqldump 2>/dev/null' ) );
                }
                if ( $found !== '' ) {
                    $first = preg_split( '/\r\n|\n|\r/', $found )[0] ?? '';
                    if ( $first !== '' && ( is_executable( $first ) || File::exists( $first ) ) ) {
                        return $first;
                    }
                }
                continue;
            }

            if ( File::exists( $candidate ) ) {
                return $candidate;
            }
        }

        $laragonMysql = 'C:\\laragon\\bin\\mysql';
        if ( is_dir( $laragonMysql ) ) {
            foreach ( File::directories( $laragonMysql ) as $dir ) {
                $bin = $dir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe';
                if ( File::exists( $bin ) ) {
                    return $bin;
                }
            }
        }

        return null;
    }

    private function zipDirectory( string $sourceDir, string $zipPath ): void
    {
        if ( ! class_exists( ZipArchive::class ) ) {
            throw new RuntimeException( 'PHP ZipArchive extension is required for backups.' );
        }

        if ( File::exists( $zipPath ) ) {
            File::delete( $zipPath );
        }

        $zip = new ZipArchive();
        if ( $zip->open( $zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            throw new RuntimeException( 'Unable to create zip archive.' );
        }

        $sourceDir = realpath( $sourceDir );
        $files     = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $sourceDir, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $files as $file ) {
            $filePath = $file->getRealPath();
            $relative = substr( $filePath, strlen( $sourceDir ) + 1 );
            $relative = str_replace( '\\', '/', $relative );

            if ( $file->isDir() ) {
                $zip->addEmptyDir( $relative );
            } else {
                $zip->addFile( $filePath, $relative );
            }
        }

        $zip->close();
    }

    private function safeName( string $name ): string
    {
        return preg_replace( '/[^A-Za-z0-9_\-]/', '_', $name ) ?: 'database';
    }

    private function escapeCnfValue( string $value ): string
    {
        return '"' . str_replace( ['\\', '"'], ['\\\\', '\"'], $value ) . '"';
    }
}
