<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table( 'tenant_materials', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'tenant_materials', 'theme_one_url' ) ) {
                $table->text( 'theme_one_url' )->nullable()->after( 'tenant_advertise_banner_url' );
            }
            if ( ! Schema::hasColumn( 'tenant_materials', 'theme_two_url' ) ) {
                $table->text( 'theme_two_url' )->nullable()->after( 'theme_one_url' );
            }
            if ( ! Schema::hasColumn( 'tenant_materials', 'theme_three_url' ) ) {
                $table->text( 'theme_three_url' )->nullable()->after( 'theme_two_url' );
            }
            if ( ! Schema::hasColumn( 'tenant_materials', 'theme_four_url' ) ) {
                $table->text( 'theme_four_url' )->nullable()->after( 'theme_three_url' );
            }
        } );
    }

    public function down(): void
    {
        Schema::table( 'tenant_materials', function ( Blueprint $table ) {
            $columns = collect( ['theme_one_url', 'theme_two_url', 'theme_three_url', 'theme_four_url'] )
                ->filter( fn( $column ) => Schema::hasColumn( 'tenant_materials', $column ) )
                ->values()
                ->all();

            if ( $columns !== [] ) {
                $table->dropColumn( $columns );
            }
        } );
    }
};
