<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Withdraw extends Model
{
    use HasFactory;

    protected $connection = 'mysql';
    protected $guarded = [];

    function affiliator(){
        return $this->belongsTo(User::class,'affiliator_id','id');
    }

    function user(){
        return $this->belongsTo(User::class,'user_id','id');
    }

    function tenant(){
        return $this->belongsTo(Tenant::class,'tenant_id','id');
    }
}
