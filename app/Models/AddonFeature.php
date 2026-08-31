<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AddonFeature extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $table = 'addon_features';

    protected $fillable = [
        'addon_id',
        'key',
        'value',
        'visibility',
    ];

    public function addon(): BelongsTo
    {
        return $this->belongsTo( Addon::class );
    }
}
