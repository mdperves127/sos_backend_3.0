<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Permission as ModelsPermission;

class Permission extends ModelsPermission
{

    protected $guarded = [];

    function rolespermission()
    {
        return $this->belongsToMany(Role::class, 'role_has_permissions');
    }
}
