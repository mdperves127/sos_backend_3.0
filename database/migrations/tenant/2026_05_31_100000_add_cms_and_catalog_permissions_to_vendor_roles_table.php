<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table( 'vendor_roles', function ( Blueprint $table ) {
            $columns = [
                'category',
                'sub_category',
                'brand',
                'cms_system',
                'cms_home_page',
                'cms_blog',
                'cms_blog_category',
            ];

            foreach ( $columns as $column ) {
                if ( !Schema::hasColumn( 'vendor_roles', $column ) ) {
                    $table->integer( $column )->nullable();
                }
            }
        } );
    }

    public function down(): void {
        Schema::table( 'vendor_roles', function ( Blueprint $table ) {
            foreach ( [
                'category',
                'sub_category',
                'brand',
                'cms_system',
                'cms_home_page',
                'cms_blog',
                'cms_blog_category',
            ] as $column ) {
                if ( Schema::hasColumn( 'vendor_roles', $column ) ) {
                    $table->dropColumn( $column );
                }
            }
        } );
    }
};
