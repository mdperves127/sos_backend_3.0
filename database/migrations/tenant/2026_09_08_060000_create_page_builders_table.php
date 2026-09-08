<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Page builder pages (tenant DB). Separate from legacy `pages` CMS table.
 */
return new class extends Migration {
    public function up(): void
    {
        if ( Schema::hasTable( 'page_builders' ) ) {
            return;
        }

        Schema::create( 'page_builders', function ( Blueprint $table ) {
            $table->id();
            $table->string( 'path' )->unique();
            $table->string( 'slug' )->unique();
            $table->string( 'page_name' );
            $table->string( 'page_type', 30 )->default( 'STANDARD' )->index();
            $table->string( 'template' )->nullable();
            $table->string( 'status', 20 )->default( 'DRAFT' )->index(); // DRAFT|PUBLISHED|ARCHIVED
            $table->json( 'blocks' )->nullable();
            $table->unsignedInteger( 'sort_order' )->default( 0 );
            $table->timestamp( 'published_at' )->nullable();
            $table->json( 'seo' )->nullable();
            $table->json( 'settings' )->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index( ['status', 'page_type'] );
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'page_builders' );
    }
};
