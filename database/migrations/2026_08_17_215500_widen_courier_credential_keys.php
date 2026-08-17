<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RedX/Steadfast tokens are JWTs longer than varchar(255).
     */
    public function up()
    {
        if ( ! Schema::hasTable( 'courier_credentials' ) ) {
            return;
        }

        DB::statement( 'ALTER TABLE `courier_credentials` MODIFY `api_key` TEXT NOT NULL' );

        if ( Schema::hasColumn( 'courier_credentials', 'secret_key' ) ) {
            DB::statement( 'ALTER TABLE `courier_credentials` MODIFY `secret_key` TEXT NULL' );
        }

        if ( Schema::hasColumn( 'courier_credentials', 'client_password' ) ) {
            DB::statement( 'ALTER TABLE `courier_credentials` MODIFY `client_password` TEXT NULL' );
        }
    }

    public function down()
    {
        if ( ! Schema::hasTable( 'courier_credentials' ) ) {
            return;
        }

        DB::statement( 'ALTER TABLE `courier_credentials` MODIFY `api_key` VARCHAR(255) NOT NULL' );

        if ( Schema::hasColumn( 'courier_credentials', 'secret_key' ) ) {
            DB::statement( 'ALTER TABLE `courier_credentials` MODIFY `secret_key` VARCHAR(255) NULL' );
        }

        if ( Schema::hasColumn( 'courier_credentials', 'client_password' ) ) {
            DB::statement( 'ALTER TABLE `courier_credentials` MODIFY `client_password` VARCHAR(255) NULL' );
        }
    }
};
