<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportBox extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    function user()
    {
        return $this->belongsTo(User::class);
    }

    function category(){
        return $this->belongsTo(SupportBoxCategory::class,'support_box_category_id');
    }

    function problem_topic(){
        return $this->belongsTo(SupportProblemTopic::class,'support_problem_topic_id');
    }

    function ticketreplay()
    {
        return $this->hasMany(TicketReply::class);
    }
    function latestTicketreplay(){
        return $this->hasOne(TicketReply::class,'support_box_id')->latestOfMany();
    }

    function supportassigned()
    {
        return $this->hasOne(SupportAssigned::class);
    }

}
