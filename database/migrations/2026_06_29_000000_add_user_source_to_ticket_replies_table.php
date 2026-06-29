<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table( 'ticket_replies', function ( Blueprint $table ) {
            $table->enum( 'user_source', ['tenant', 'admin'] )->nullable()->after( 'user_id' );
        } );

        if ( ! Schema::hasTable( 'ticket_replies' ) || ! Schema::hasTable( 'support_boxes' ) ) {
            return;
        }

        DB::statement( "
            UPDATE ticket_replies tr
            INNER JOIN support_boxes sb ON sb.id = tr.support_box_id
            SET tr.user_source = CASE
                WHEN tr.status = 'answered' THEN 'admin'
                WHEN tr.status = 'replied' THEN 'tenant'
                WHEN sb.tenant_id IS NOT NULL AND sb.tenant_id != '' AND tr.user_id = sb.user_id THEN 'tenant'
                ELSE 'admin'
            END
            WHERE tr.user_source IS NULL
        " );

        DB::table( 'ticket_replies' )
            ->whereNull( 'user_source' )
            ->update( ['user_source' => 'admin'] );
    }

    public function down(): void {
        Schema::table( 'ticket_replies', function ( Blueprint $table ) {
            $table->dropColumn( 'user_source' );
        } );
    }
};
