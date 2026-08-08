<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create( 'tenant_verifications', function ( Blueprint $table ) {
            $table->id();
            $table->string( 'email' )->nullable()->index();
            $table->string( 'phone', 20 )->nullable()->index();
            $table->string( 'email_verify_code', 6 )->nullable();
            $table->timestamp( 'email_verify_code_at' )->nullable();
            $table->timestamp( 'email_verified_at' )->nullable();
            $table->string( 'phone_verify_code', 6 )->nullable();
            $table->timestamp( 'phone_verify_code_at' )->nullable();
            $table->timestamp( 'phone_verified_at' )->nullable();
            $table->timestamps();
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'tenant_verifications' );
    }
};
