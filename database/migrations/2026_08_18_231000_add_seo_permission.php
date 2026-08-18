<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up()
    {
        if ( ! Schema::hasTable( 'permissions' ) ) {
            return;
        }

        $now = now();

        $exists = DB::table( 'permissions' )
            ->where( 'name', 'seo' )
            ->where( 'guard_name', 'web' )
            ->exists();

        if ( ! $exists ) {
            DB::table( 'permissions' )->insert( [
                'name'       => 'seo',
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ] );
        }

        $seo = DB::table( 'permissions' )->where( 'name', 'seo' )->where( 'guard_name', 'web' )->first();
        $faq = DB::table( 'permissions' )->where( 'name', 'faq' )->where( 'guard_name', 'web' )->first();

        if ( ! $seo || ! $faq || ! Schema::hasTable( 'role_has_permissions' ) ) {
            return;
        }

        $roleIds = DB::table( 'role_has_permissions' )
            ->where( 'permission_id', $faq->id )
            ->pluck( 'role_id' );

        foreach ( $roleIds as $roleId ) {
            $alreadyAssigned = DB::table( 'role_has_permissions' )
                ->where( 'permission_id', $seo->id )
                ->where( 'role_id', $roleId )
                ->exists();

            if ( ! $alreadyAssigned ) {
                DB::table( 'role_has_permissions' )->insert( [
                    'permission_id' => $seo->id,
                    'role_id'       => $roleId,
                ] );
            }
        }

        if ( class_exists( Permission::class ) ) {
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        }
    }

    public function down()
    {
        if ( ! Schema::hasTable( 'permissions' ) ) {
            return;
        }

        $seo = DB::table( 'permissions' )->where( 'name', 'seo' )->where( 'guard_name', 'web' )->first();

        if ( ! $seo ) {
            return;
        }

        if ( Schema::hasTable( 'role_has_permissions' ) ) {
            DB::table( 'role_has_permissions' )->where( 'permission_id', $seo->id )->delete();
        }

        DB::table( 'permissions' )->where( 'id', $seo->id )->delete();
    }
};
