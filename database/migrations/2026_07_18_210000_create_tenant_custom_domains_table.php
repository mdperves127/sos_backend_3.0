<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_custom_domains', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('domain', 255);
            $table->enum('status', ['pending', 'verified', 'active'])->default('pending');
            $table->enum('verification', ['pending', 'verified'])->default('pending');
            $table->enum('ssl', ['pending', 'issuing', 'active'])->default('pending');
            $table->string('target_ip', 45)->nullable();
            $table->json('last_dns_check')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unique('domain');
            $table->unique('tenant_id');
            $table->index(['status', 'verification']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_custom_domains');
    }
};
