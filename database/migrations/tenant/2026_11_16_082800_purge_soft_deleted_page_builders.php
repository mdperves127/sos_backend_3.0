<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-deleted page_builders still blocked unique slug/path recreates.
 * Purge them so deleted pages can be created again.
 */
return new class extends Migration {
    public function up(): void
    {
        if ( ! Schema::hasTable( 'page_builders' ) ) {
            return;
        }

        if ( ! Schema::hasColumn( 'page_builders', 'deleted_at' ) ) {
            return;
        }

        DB::table( 'page_builders' )->whereNotNull( 'deleted_at' )->delete();
    }

    public function down(): void
    {
        // Irreversible cleanup.
    }
};
