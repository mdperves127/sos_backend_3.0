<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        DB::table( 'support_boxes' )->where( function ( $q ) {
            $q->whereNull( 'status' )->orWhere( 'status', '' );
        } )->update( ['status' => 'new_ticket'] );
    }

    public function down(): void {
        //
    }
};
