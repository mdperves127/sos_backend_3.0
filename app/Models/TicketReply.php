<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketReply extends Model {
    use HasFactory, SoftDeletes;

    protected $fillable = ['support_box_id', 'description', 'user_id', 'status', 'read_status'];

    function file() {
        return $this->morphOne( File::class, 'filetable' );
    }

    function user() {
        return $this->belongsTo( User::class, 'user_id' );

    }

    public function supportBox() {
        return $this->belongsTo( SupportBox::class );
    }
}
