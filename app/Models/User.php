<?php

namespace App\Models;

//use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable {

    use HasApiTokens,
    HasFactory,
    Notifiable,
    HasRoles;

        /**
     * Get the database connection for the model.
     *
     * @return \Illuminate\Database\Connection
     */
    public function getConnectionName()
    {
        // If we're in a tenant context, use the tenant connection
        if (function_exists('tenant') && tenant()) {
            return 'tenant';
        }

        return parent::getConnectionName();
    }

    /**
     * Get the access tokens that belong to model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function tokens()
    {
        return $this->morphMany(\App\Models\PersonalAccessToken::class, 'tokenable');
    }
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role_as',
        'number',
        'image',
        'status',
        'balance',
        'uniqid',
        'last_seen',
        'verify_code',
        'email_verified_at',
        'remember_token',
        'verify_code_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];
    function brands() {
        return $this->hasMany( Brand::class, 'user_id', 'id' );
    }
    function getVendorBrands() {
        return $this->hasMany( Brand::class, 'user_id', 'id' )->where( 'status', 'active' );
    }

    function usersubscription() {
        return $this->hasOne( UserSubscription::class, 'user_id' );
    }

    function vendorsubscription() {
        return $this->hasOne( UserSubscription::class, 'user_id' );
    }

    function usersubscriptions() {
        return $this->hasMany( UserSubscription::class, 'user_id' );
    }

    function paymenthistories() {
        return $this->hasMany( PaymentHistory::class, 'user_id' );
    }

    function affiliatoractiveproducts() {
        return $this->hasMany( ProductDetails::class, 'user_id' );
    }

    function vendoractiveproduct() {
        return $this->hasMany( ProductDetails::class, 'vendor_id' );
    }

    function scopeSearch( $query, $value ) {
        $query->where( 'email', 'like', "%{$value}%" )
            ->orWhere( 'uniqid', 'like', "%{$value}%" );
    }

    public function chatSent() {
        return $this->hasMany( Chat::class, 'sender_id' );
    }

    public function chatReceived() {
        return $this->hasMany( Chat::class, 'recipient_id' );
    }

    public function messages() {
        return $this->belongsToMany( Chat::class );
    }

    public function productDetails() {

        if ( Auth::user()->role_as == 3 ) {
            return $this->hasMany( ProductDetails::class, 'vendor_id' );
        } elseif ( Auth::user()->role_as == 2 ) {
            return $this->hasMany( ProductDetails::class, 'user_id' );
        }

    }

}
