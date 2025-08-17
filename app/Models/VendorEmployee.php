<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorEmployee extends Model {
    use HasFactory;

    protected $guarded = [];

    function user() {
        return $this->belongsTo( User::class );
    }

    function vendor_role() {
        return $this->belongsTo( VendorRole::class );
    }

}
