<?php

use App\Models\Coupon;
use App\Models\CourierCredential;
use App\Models\RolePermission;
use App\Models\Settings;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\WoocommerceCredential;
use App\Helper\RedirectHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

function slugCreate( $modelName, $slug_text, $slugColumn = 'slug' ) {
    $slug = Str::slug( $slug_text, '-' );
    $i    = 1;
    while ( $modelName::where( $slugColumn, $slug )->exists() ) {
        $slug = Str::slug( $slug_text, '-' ) . '-' . $i++;
    }
    return $slug;
}

function slugUpdate( $modelName, $slug_text, $modelId, $slugColumn = 'slug' ) {
    $slug = Str::slug( $slug_text, '-' );
    $i    = 1;
    while ( $modelName::where( $slugColumn, $slug )->where( 'id', '!=', $modelId )->exists() ) {
        $slug = Str::slug( $slug_text, '-' ) . '-' . $i++;
    }
    return $slug;
}

function fileUpload( $file, $path, $withd = null, $height = null, $quality = 100 ) {
    $extension  = strtolower( $file->getClientOriginalExtension() ?: 'jpg' );
    $image_name = uniqid() . '-' . time() . '.' . $extension;
    $imagePath  = $path . '/' . $image_name;
    $fullPath   = public_path( $imagePath );

    $directory = dirname( $fullPath );
    if ( ! is_dir( $directory ) ) {
        mkdir( $directory, 0755, true );
    }

    $file->move( $directory, $image_name );

    return $imagePath;
}

function productImageUpload( $file, $path = 'uploads/product' ) {
    return fileUpload( $file, $path );
}

function fileUploadFromUrl( $url, $path, $width = null, $height = null, $quality = 100 ) {
    $extension = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) ?: 'jpg' );
    $imageName = Str::random( 10 ) . '-' . time() . '.' . $extension;
    $imagePath = $path . '/' . $imageName;
    $fullPath  = public_path( $imagePath );

    $directory = dirname( $fullPath );
    if ( ! is_dir( $directory ) ) {
        mkdir( $directory, 0755, true );
    }

    $imageContents = file_get_contents( $url );
    file_put_contents( $fullPath, $imageContents );

    return $imagePath;
}

function orderId() {
    $timestamp    = now()->format( 'YmdHis' );
    $randomString = Str::random( 6 );
    return $timestamp . $randomString;
}

function responsejson( $message, $data = "success" ) {
    return response()->json(
        [
            'data'    => $data,
            'message' => $message,
        ]
    );
}

function userid() {
    return auth()->user()->id;
}

function upload_image( $filename, $width, $height, ) {
    $imagename = uniqid() . '.' . $filename->getClientOriginalExtension();
    $targetDir = public_path( 'assets/images' );

    if ( ! is_dir( $targetDir ) ) {
        mkdir( $targetDir, 0755, true );
    }

    $filename->move( $targetDir, $imagename );
    $image_upload = 'assets/images/' . $imagename;
    return $image_upload;
}

function handleUpdatedUploadedImage( $file, $path, $data, $delete_path, $field ) {
    $name = time() . $file->getClientOriginalName();

    $file->move( base_path( 'public/' ) . $path, $name );
    if ( $data[$field] != null ) {
        if ( file_exists( base_path( 'public/' ) . $delete_path . $data[$field] ) ) {
            unlink( base_path( 'public/' ) . $delete_path . $data[$field] );
        }
    }
    return $name;
}

if ( !function_exists( 'uploadany_file' ) ) {
    function uploadany_file( $filename, $path = 'uploads/support/' ) {
        $uploadPath = $path;
        $i          = 1;

        $extension = $filename->getClientOriginalExtension();
        $name      = uniqid() . $i++ . '.' . $extension;
        $filename->move( $uploadPath, $name );

        return $uploadPath . $name;
    }
}

function userrole( $roleid ) {
    if ( $roleid == 2 ) {
        return "vendor";
    }
    if ( $roleid == 3 ) {
        return "affiliate";
    }
    if ( $roleid == 4 ) {
        return "user";
    }
}

function convertfloat( $originalNumber ) {
    return str_replace( ',', '', $originalNumber );
}

function membershipexpiredate( $value ) {
    if ( $value == 'monthly' ) {
        return now()->addMonth( 1 );
    } elseif ( $value == 'half_yearly' ) {
        return now()->addMonth( 6 );
    } elseif ( $value == 'yearly' ) {
        return now()->addYear( 1 );
    }
}

function getmonth( $monthname ) {
    if ( $monthname == 'monthly' ) {
        return 1;
    } elseif ( $monthname == 'half_yearly' ) {
        return 6;
    } elseif ( $monthname == 'yearly' ) {
        return 12;
    }
}

function ismembershipexists( $userid = null ) {
    if ( !$userid ) {
        $userid = auth()->id();
    }
    return UserSubscription::on('mysql')->where( ['user_id' => $userid] )->exists();
}

function isactivemembership( $userid = null ) {
    if ( !$userid ) {
        $userid = auth()->id();
    }

    $usersubscription = UserSubscription::on('mysql')->where( ['user_id' => $userid] )->first();
    $sub              = Subscription::on('mysql')->find( $usersubscription->subscription_id );
    if ( $sub->subscription_amount != 0 ) {
        $date = Carbon::parse( $usersubscription->expire_date )->addMonth( 1 );
        if ( $date > now() ) {
            return 1;
        }
        return;
    } else {
        $freesubscriptiondate = Carbon::parse( $usersubscription->expire_date );
        if ( $freesubscriptiondate > now() ) {
            return 1;
        }
        return;
    }
}

function getmembershipdetails( $userid = null ) {
    if ( !$userid ) {
        $userid = auth()->id();
    }

    return UserSubscription::on('mysql')->where( ['user_id' => vendorId()] )->first();
}

function paymentredirect( $role ) {
    if ( userrole( $role ) == 'vendor' ) {
        return 'vendors-dashboard';
    }
    if ( userrole( $role ) == 'affiliate' ) {
        return 'affiliates-dashboard';
    }
    if ( userrole( $role ) == 'user' ) {
        return 'users-dashboard';
    }
}

/**
 * Get redirect URL - when in tenant context returns tenant subdomain URL dynamically.
 *
 * @return string Base URL for redirects (with trailing slash)
 */
function getRedirectUrl() {
    return RedirectHelper::getRedirectUrl();
}

function couponget( $coupon_id ) {
    $query = Coupon::on('mysql')
        ->where( ['id' => $coupon_id, 'status' => 'active'] )
        ->whereDate( 'expire_date', '>=', now() )
        ->withCount( 'couponused' );

    if ( function_exists( 'tenant' ) && tenant() ) {
        $query->where( 'tenant_id', '!=', tenant()->id );
    } else {
        $query->where( 'user_id', '!=', auth()->id() );
    }

    $coupon = $query->first();

    if ( $coupon ) {
        $couponused = $coupon->couponused()->count();
        if ( $coupon->limitation <= $couponused ) {
            return;
        }
        return $coupon;
    }
}

function checkpermission( $permission ) {

    $permission = Permission::where( 'name', $permission )->first();
    if ( !$permission ) {
        return false;
    }
    $userrole       = DB::table( 'model_has_roles' )->where( 'model_id', auth()->id() )->first();
    $rolepermission = RolePermission::where( 'permission_id', $permission->id )->where( 'role_id', $userrole->role_id )->first();
    if ( !$rolepermission ) {
        return false;
    }

    $userrole = DB::table( 'model_has_roles' )->where( 'model_id', auth()->id() )->first();
    if ( !$userrole ) {
        return false;
    }

    if ( $userrole?->role_id == $rolepermission?->role_id ) {
        return 1;
    }
    return false;

}

function colculateflatpercentage( $type, $amount, $discountamount ) {
    if ( $type == 'flat' ) {
        return $discountamount;
    } else {
        return ( $amount / 100 ) * $discountamount;
    }
}

function generateRandomString( $length = 10 ) {
    $characters   = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $randomString = substr( str_shuffle( $characters ), 0, $length ); // Generates a random string of length 8
    return $randomString;
}

function barcode( $length = 12 ) {
    $characters   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $randomString = substr( str_shuffle( $characters ), 0, $length ); // Generates a random string of length 8
    return $randomString;
}

function vendorId() {
    if ( function_exists( 'tenant' ) && tenant() ) {
        return tenantOwnerId();
    }

    return Auth::user()->is_employee == 'yes' ? Auth::user()->vendor_id : Auth::id();
}

/**
 * Tenant owner user id (admin). Employees resolve owner via their assigned role.
 */
function tenantOwnerId() {
    $user = Auth::user();
    if ( !$user ) {
        return null;
    }
    if ( ( $user->role_type ?? null ) === 'admin' ) {
        return $user->id;
    }
    if ( ( $user->role_type ?? null ) === 'employee' && $user->vendor_role_id ) {
        $role = $user->relationLoaded( 'vendorRole' )
            ? $user->vendorRole
            : \App\Models\VendorRole::find( $user->vendor_role_id );
        return $role?->vendor_id ?? $user->id;
    }

    return $user->id;
}

/**
 * Whether the current tenant user is the tenant owner (admin).
 */
function isTenantAdmin(): bool {
    return Auth::check() && ( Auth::user()->role_type ?? null ) === 'admin';
}

/**
 * Check a permission flag from the employee's vendor_role. Admins always allowed.
 */
/**
 * Base query for reviews that may appear on the tenant storefront.
 */
function visibleProductRatingsQuery( ?string $connection = null ) {
    $query = $connection
        ? \App\Models\ProductRating::on( $connection )
        : \App\Models\ProductRating::query();

    return $query->visibleOnFrontend();
}

function tenantPermission( string $permission ): bool {
    if ( !Auth::check() ) {
        return false;
    }
    if ( isTenantAdmin() ) {
        return true;
    }
    if ( ( Auth::user()->role_type ?? null ) !== 'employee' || !Auth::user()->vendor_role_id ) {
        return false;
    }
    $role = Auth::user()->relationLoaded( 'vendorRole' )
        ? Auth::user()->vendorRole
        : \App\Models\VendorRole::find( Auth::user()->vendor_role_id );

    return $role && (int) ( $role->{$permission} ?? 0 ) === 1;
}

function employee( $value ) {
    $employee = User::join( 'vendor_employees', 'users.id', 'vendor_employees.user_id' )
        ->select( 'users.id as userId', 'name', 'email', 'number', 'users.vendor_id as vendorId', 'vendor_employees.*' )
        ->where( 'users.id', Auth::id() )
        ->first()->$value;

    return $employee;
}

function employeePermission( $value ) {
    if ( Auth::user()->is_employee === 'yes' && $value === null ) {
        return response()->json( [
            'status'  => 400,
            'message' => 'No permission',
        ] );
    }
}

function otpType() {
    try {
        $settings = \App\Models\Settings::on( 'mysql' )->first()
            ?? \App\Models\Settings::query()->first();

        return $settings?->otp_type ?: 'email';
    } catch ( \Throwable $e ) {
        return 'email';
    }
}

function extraCharge( $amount, $percent = 0 ) {
    return $amount / 100 * $percent;

}

function percentage( $amount, $discountAmount ) {
    $remainingValue = $amount - $discountAmount;
    return ( $remainingValue / $amount ) * 100;
}

function wcCredential() {
    return WoocommerceCredential::where( 'vendor_id', Auth::id() )->first();
}

if ( !function_exists( 'getWcItemTotalQty' ) ) {
    function getWcItemTotalQty( $order ) {

        $items         = $order['line_items'];
        $quantities    = array_column( $items, 'quantity' );
        $totalQuantity = array_sum( $quantities );

        return $totalQuantity;
    }
}

if ( !function_exists( 'generateSKU' ) ) {
    function generateSKU() {
        return strtoupper( Str::random( 6 ) ) . rand( 100, 999 ) . strtoupper( Str::random( 3 ) );
    }
}

if ( !function_exists( 'courierCredential' ) ) {
    function courierCredential( $vendorId, $courierName ) {
        $default = CourierCredential::where( 'vendor_id', $vendorId )
            ->where( 'courier_name', $courierName )
            ->where( 'status', 'active' )
            ->where( 'default', 'yes' )
            ->first();

        if ( $default ) {
            return $default;
        }

        $active = CourierCredential::where( 'vendor_id', $vendorId )
            ->where( 'courier_name', $courierName )
            ->where( 'status', 'active' )
            ->orderByDesc( 'id' )
            ->first();

        if ( $active ) {
            return $active;
        }

        return CourierCredential::where( 'vendor_id', $vendorId )
            ->where( 'courier_name', $courierName )
            ->orderByDesc( 'id' )
            ->first();
    }
}

/**
 * Resolve Pathao (or other) courier credential by tenant_id.
 * Uses the current tenant DB when possible; otherwise queries the target tenant.
 */
if ( ! function_exists( 'courierCredentialByTenant' ) ) {
    function courierCredentialByTenant( $tenantId = null, string $courierName = 'pathao' ) {
        $tenantId = $tenantId
            ?: ( function_exists( 'tenant' ) && tenant() ? tenant( 'id' ) : null )
            ?: request()->input( 'tenant_id' );

        if ( ! $tenantId ) {
            return courierCredential( vendorId(), $courierName );
        }

        $currentTenantId = function_exists( 'tenant' ) && tenant() ? (string) tenant( 'id' ) : null;
        $useLocal        = $currentTenantId !== null && $currentTenantId === (string) $tenantId;

        if ( $useLocal ) {
            return courierCredential( vendorId(), $courierName );
        }

        $credential = \App\Services\CrossTenantQueryService::getSingleFromTenant(
            $tenantId,
            CourierCredential::class,
            function ( $query ) use ( $courierName ) {
                $query->where( 'courier_name', $courierName )
                    ->where( 'status', 'active' )
                    ->where( 'default', 'yes' );
            }
        );

        if ( $credential ) {
            return $credential;
        }

        return \App\Services\CrossTenantQueryService::getSingleFromTenant(
            $tenantId,
            CourierCredential::class,
            function ( $query ) use ( $courierName ) {
                $query->where( 'courier_name', $courierName )
                    ->where( 'status', 'active' )
                    ->orderByDesc( 'id' );
            }
        );
    }
}

/**
 * Build Laravel-style truncated pagination links (e.g. 1 … 108 109 110 … 112).
 *
 * @param  callable(int): string  $buildUrl
 * @return array<int, array{url: ?string, label: string, active: bool}>
 */
if ( ! function_exists( 'paginationLinks' ) ) {
    function paginationLinks( int $currentPage, int $lastPage, callable $buildUrl, int $onEachSide = 2 ): array {
        $currentPage = max( 1, $currentPage );
        $lastPage    = max( 0, $lastPage );

        $links   = [];
        $links[] = [
            'url'    => $currentPage > 1 ? $buildUrl( $currentPage - 1 ) : null,
            'label'  => '&laquo; Previous',
            'active' => false,
        ];

        if ( $lastPage > 0 ) {
            foreach ( paginationPageWindow( $currentPage, $lastPage, $onEachSide ) as $page ) {
                if ( $page === '...' ) {
                    $links[] = [
                        'url'    => null,
                        'label'  => '...',
                        'active' => false,
                    ];
                    continue;
                }

                $links[] = [
                    'url'    => $buildUrl( $page ),
                    'label'  => (string) $page,
                    'active' => $page === $currentPage,
                ];
            }
        }

        $links[] = [
            'url'    => $currentPage < $lastPage ? $buildUrl( $currentPage + 1 ) : null,
            'label'  => 'Next &raquo;',
            'active' => false,
        ];

        return $links;
    }
}

/**
 * Page numbers / ellipsis for a truncated window (mirrors Laravel UrlWindow).
 *
 * @return array<int, int|string>
 */
if ( ! function_exists( 'paginationPageWindow' ) ) {
    function paginationPageWindow( int $currentPage, int $lastPage, int $onEachSide = 2 ): array {
        if ( $lastPage <= ( $onEachSide * 2 ) + 8 ) {
            return range( 1, $lastPage );
        }

        $window = $onEachSide + 4;

        if ( $currentPage <= $window ) {
            return array_merge(
                range( 1, $window + $onEachSide ),
                ['...'],
                range( $lastPage - 1, $lastPage )
            );
        }

        if ( $currentPage > ( $lastPage - $window ) ) {
            return array_merge(
                range( 1, 2 ),
                ['...'],
                range( $lastPage - ( $window + ( $onEachSide - 1 ) ), $lastPage )
            );
        }

        return array_merge(
            range( 1, 2 ),
            ['...'],
            range( $currentPage - $onEachSide, $currentPage + $onEachSide ),
            ['...'],
            range( $lastPage - 1, $lastPage )
        );
    }
}
