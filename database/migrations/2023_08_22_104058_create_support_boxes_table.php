<?php

use App\Enums\Status;
use App\Models\SupportBoxCategory;
use App\Models\SupportProblemTopic;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('support_boxes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class);
            $table->foreignIdFor(SupportBoxCategory::class);
            $table->foreignIdFor(SupportProblemTopic::class);
            $table->enum('status',[Status::Pending->value,Status::Progress->value ,Status::Delivered->value,'answered']);
            $table->text('description');
            $table->string('file')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('support_boxes');
    }
};
