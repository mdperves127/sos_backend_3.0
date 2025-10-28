<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\Status;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('support_boxes', function (Blueprint $table) {
            $table->enum('status',['Status::Pending->value,Status::Progress->value ,Status::Delivered->value','answered', 'closed', 'new ticket'])->default('new ticket');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('support_boxes', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
