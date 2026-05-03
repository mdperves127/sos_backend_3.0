<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketReply extends Model {
    use HasFactory, SoftDeletes;

    /** Central SOS data; keep off the tenant default connection when middleware sets DB to tenant. */
    protected $connection = 'mysql';

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
