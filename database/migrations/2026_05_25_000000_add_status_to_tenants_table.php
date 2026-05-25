<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->enum('status', ['pending', 'active', 'blocked'])
                ->default('pending')
                ->after('type');
        });

        foreach (DB::table('tenants')->select('id', 'data')->get() as $tenant) {
            $data   = json_decode($tenant->data, true) ?? [];
            $status = $data['status'] ?? 'active';

            if ( ! in_array( $status, ['pending', 'active', 'blocked'], true ) ) {
                $status = 'active';
            }

            DB::table('tenants')->where('id', $tenant->id)->update(['status' => $status]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
