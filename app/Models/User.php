<?php

namespace App\Models;

//use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable {

    use HasApiTokens,
    HasFactory,
    Notifiable,
    HasRoles;

    // Temporarily remove custom connection logic to isolate memory issues
    // public function getConnectionName()
    // {
    //     // Temporarily always use central database to isolate issues
    //     return env('DB_CONNECTION', 'mysql');
    // }

    /**
     * Get the access tokens that belong to model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function tokens()
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable');
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
        'role_type',
        'vendor_role_id',
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
    function vendorRole() {
        return $this->belongsTo( VendorRole::class, 'vendor_role_id' );
    }

    function brands() {
        return $this->hasMany( Brand::class, 'user_id', 'id' );
    }
    function getVendorBrands() {
        return $this->hasMany( Brand::class, 'user_id', 'id' )->where( 'status', 'active' );
    }

    private function centralUserSubscriptionInstance(): UserSubscription {
        return (new UserSubscription)->setConnection( 'mysql' );
    }

    function usersubscription(): HasOne {
        $related = $this->centralUserSubscriptionInstance();

        return new HasOne(
            $related->newQuery(),
            $this,
            $related->getTable() . '.user_id',
            $this->getKeyName()
        );
    }

    function vendorsubscription(): HasOne {
        return $this->usersubscription();
    }

    function usersubscriptions(): HasMany {
        $related = $this->centralUserSubscriptionInstance();

        return new HasMany(
            $related->newQuery(),
            $this,
            $related->getTable() . '.user_id',
            $this->getKeyName()
        );
    }

    /**
     * user_subscriptions lives on mysql; tenant queries must qualify the central database.
     */
    public function scopeWhereCentralSubscription( $query, bool $active = true ) {
        $centralDb = DB::connection( 'mysql' )->getDatabaseName();
        $operator  = $active ? '>' : '<=';

        return $query->whereExists( function ( $sub ) use ( $centralDb, $operator ) {
            $sub->select( DB::raw( 1 ) )
                ->from( DB::raw( "`{$centralDb}`.`user_subscriptions`" ) )
                ->whereColumn( "{$centralDb}.user_subscriptions.user_id", 'users.id' )
                ->where( "{$centralDb}.user_subscriptions.expire_date", $operator, now() )
                ->whereNull( "{$centralDb}.user_subscriptions.deleted_at" );
        } );
    }

    /**
     * Active product count must not exceed product_approve on central user_subscriptions.
     * Uses whereRaw because HAVING aliases break inside whereHas EXISTS subqueries.
     */
    public function scopeWithinCentralSubscriptionProductApproveLimit( $query ) {
        $centralDb = DB::connection( 'mysql' )->getDatabaseName();

        return $query->whereRaw(
            '(SELECT COUNT(*) FROM `product_details` pd WHERE pd.user_id = users.id AND pd.status = 1) <= '
            . "(SELECT COALESCE(SUM(us.product_approve), 0) FROM `{$centralDb}`.`user_subscriptions` us "
            . 'WHERE us.user_id = users.id AND us.deleted_at IS NULL)'
        );
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
