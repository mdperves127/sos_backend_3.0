<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        Schema::create( 'delivery_companies', function ( Blueprint $table ) {
            $table->id();
            $table->foreignIdFor( User::class );
            $table->string( 'vendor_id' );
            $table->string( 'company_name' );
            $table->string( 'company_slug' );
            $table->string( 'phone' )->nullable();
            $table->string( 'email' )->nullable();
            $table->string( 'address' )->nullable();
            $table->enum( 'status', ['active', 'deactive'] )->nullable()->default( 'active' );
            $table->softDeletes();
            $table->timestamps();
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::dropIfExists( 'delivery_companies' );
    }
};
