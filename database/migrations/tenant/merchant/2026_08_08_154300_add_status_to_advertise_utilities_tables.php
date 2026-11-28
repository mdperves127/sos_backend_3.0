<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'campaign_categories',
        'conversion_locations',
        'perfomance_goals',
        'placements',
    ];

    public function up(): void
    {
        foreach ( $this->tables as $tableName ) {
            if ( ! Schema::hasTable( $tableName ) || Schema::hasColumn( $tableName, 'status' ) ) {
                continue;
            }

            Schema::table( $tableName, function ( Blueprint $table ) {
                $table->enum( 'status', ['active', 'inactive'] )->default( 'active' );
            } );
        }
    }

    public function down(): void
    {
        foreach ( $this->tables as $tableName ) {
            if ( ! Schema::hasTable( $tableName ) || ! Schema::hasColumn( $tableName, 'status' ) ) {
                continue;
            }

            Schema::table( $tableName, function ( Blueprint $table ) {
                $table->dropColumn( 'status' );
            } );
        }
    }
};
