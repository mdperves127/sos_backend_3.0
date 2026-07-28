<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_twos', function (Blueprint $table) {
            $table->string('photo')->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('organization_twos', function (Blueprint $table) {
            $table->dropColumn('photo');
        });
    }
};
