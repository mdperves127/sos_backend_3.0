<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up()
    {
        if ( ! Schema::hasTable( 'permissions' ) ) {
            return;
        }

        $now = now();

        $webSeo = DB::table( 'permissions' )
            ->where( 'name', 'seo' )
            ->where( 'guard_name', 'web' )
            ->first();

        if ( $webSeo ) {
            if ( Schema::hasTable( 'role_has_permissions' ) ) {
                DB::table( 'role_has_permissions' )->where( 'permission_id', $webSeo->id )->delete();
            }

            DB::table( 'permissions' )->where( 'id', $webSeo->id )->delete();
        }

        $exists = DB::table( 'permissions' )
            ->where( 'name', 'seo' )
            ->where( 'guard_name', 'sanctum' )
            ->exists();

        if ( ! $exists ) {
            DB::table( 'permissions' )->insert( [
                'name'       => 'seo',
                'guard_name' => 'sanctum',
                'created_at' => $now,
                'updated_at' => $now,
            ] );
        }

        $seo = DB::table( 'permissions' )->where( 'name', 'seo' )->where( 'guard_name', 'sanctum' )->first();
        $faq = DB::table( 'permissions' )->where( 'name', 'faq' )->where( 'guard_name', 'sanctum' )->first();

        if ( $seo && $faq && Schema::hasTable( 'role_has_permissions' ) ) {
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
        }

        if ( class_exists( PermissionRegistrar::class ) ) {
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
        }
    }

    public function down()
    {
        if ( ! Schema::hasTable( 'permissions' ) ) {
            return;
        }

        $seo = DB::table( 'permissions' )->where( 'name', 'seo' )->where( 'guard_name', 'sanctum' )->first();

        if ( ! $seo ) {
            return;
        }

        if ( Schema::hasTable( 'role_has_permissions' ) ) {
            DB::table( 'role_has_permissions' )->where( 'permission_id', $seo->id )->delete();
        }

        DB::table( 'permissions' )->where( 'id', $seo->id )->delete();
    }
};
