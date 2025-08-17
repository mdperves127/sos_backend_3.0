<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdminAdvertise extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'ad_creative' => 'json',
        'placements' => 'json',
        'device' => 'json',
        'platform' => 'json',
        'city'=>'json'
    ];

    public function AdvertiseAudienceFile()
    {
        return $this->hasMany(AdvertiseAudienceFile::class, 'advertise_id');
    }
    public function advertisePlacement()
    {
        return $this->hasMany(AdvertisePlacement::class, 'advertise_id');
    }

    public function advertiseLocationFiles()
    {
        return $this->hasMany(LocationFile::class, 'advertise_id');
    }

    function files()
    {
        return $this->morphMany(File::class, 'filetable');
    }

    protected static function boot()
    {
        parent::boot();

        static::deleted(function ($query) {
            $query->AdvertiseAudienceFile()->delete();
            $query->advertisePlacement()->delete();
            $query->advertiseLocationFiles()->delete();
        });
    }
    function user()
    {
        return $this->belongsTo(User::class);
    }
}
