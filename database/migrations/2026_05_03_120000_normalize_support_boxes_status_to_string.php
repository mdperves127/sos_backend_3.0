<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Use a string status column and normalize legacy enum values.
     */
    public function up(): void {
        DB::statement( 'ALTER TABLE support_boxes MODIFY status VARCHAR(32) NOT NULL DEFAULT "new_ticket"' );

        DB::table( 'support_boxes' )->where( 'status', 'new ticket' )->update( ['status' => 'new_ticket'] );
        DB::table( 'support_boxes' )->whereIn( 'status', ['pending', 'progress', 'delivered'] )->update( ['status' => 'new_ticket'] );
        DB::table( 'support_boxes' )->where( 'is_close', 1 )->update( ['status' => 'closed'] );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        // Non-trivial to restore the previous ENUM; run a forward migration if you need rollback.
    }
};
