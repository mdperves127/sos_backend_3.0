<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if ( ! $this->tableExists() ) {
            return;
        }

        DB::statement(
            "ALTER TABLE `tenant_addons` MODIFY `status` ENUM('pending', 'active', 'inactive', 'cancelled') NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        if ( ! $this->tableExists() ) {
            return;
        }

        DB::table( 'tenant_addons' )->where( 'status', 'inactive' )->update( ['status' => 'cancelled'] );

        DB::statement(
            "ALTER TABLE `tenant_addons` MODIFY `status` ENUM('pending', 'active', 'cancelled') NOT NULL DEFAULT 'pending'"
        );
    }

    private function tableExists(): bool
    {
        return DB::getSchemaBuilder()->hasTable( 'tenant_addons' );
    }
};
