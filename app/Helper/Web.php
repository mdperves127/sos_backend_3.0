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
use Intervention\Image\Facades\Image;
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

function fileUpload( $file, $path, $withd = 400, $height = 400, $quality = 90 ) {
    $extension  = strtolower( $file->getClientOriginalExtension() ?: 'jpg' );
    $image_name = uniqid() . '-' . time() . '.' . $extension;
    $imagePath  = $path . '/' . $image_name;
    $fullPath   = public_path( $imagePath );

    $directory = dirname( $fullPath );
    if ( ! is_dir( $directory ) ) {
        mkdir( $directory, 0755, true );
    }

    $image = Image::make( $file );
    $image->resize( $withd, $height, function ( $constraint ) {
        $constraint->aspectRatio();
        $constraint->upsize();
    } );

    if ( in_array( $extension, ['jpg', 'jpeg'], true ) ) {
        $image->encode( 'jpg', $quality )->save( $fullPath );
    } elseif ( $extension === 'webp' ) {
        $image->encode( 'webp', $quality )->save( $fullPath );
    } else {
        $image->save( $fullPath );
    }

    return $imagePath;
}

function productImageUpload( $file, $path = 'uploads/product' ) {
    return fileUpload( $file, $path, 1200, 1200, 92 );
}

function fileUploadFromUrl( $url, $path, $width = 400, $height = 400, $quality = 90 ) {
    $extension = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) ?: 'jpg' );
    $imageName = Str::random( 10 ) . '-' . time() . '.' . $extension;
    $imagePath = $path . '/' . $imageName;
    $fullPath  = public_path( $imagePath );

    $directory = dirname( $fullPath );
    if ( ! is_dir( $directory ) ) {
        mkdir( $directory, 0755, true );
    }

    $imageContents = file_get_contents( $url );
    $tempPath      = sys_get_temp_dir() . '/' . $imageName;

    file_put_contents( $tempPath, $imageContents );

    $image = Image::make( $tempPath );
    $image->resize( $width, $height, function ( $constraint ) {
        $constraint->aspectRatio();
        $constraint->upsize();
    } );

    if ( in_array( $extension, ['jpg', 'jpeg'], true ) ) {
        $image->encode( 'jpg', $quality )->save( $fullPath );
    } elseif ( $extension === 'webp' ) {
        $image->encode( 'webp', $quality )->save( $fullPath );
    } else {
        $image->save( $fullPath );
    }

    unlink( $tempPath );

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
    $new_webp  = preg_replace( '"\.(jpg|jpeg|png|webp)$"', '.webp', $imagename );

    Image::make( $filename )->encode( 'webp', 90 )->fit( $width, $height )->save( 'assets/images/' . $new_webp );
    $image_upload = 'assets/images/' . $new_webp;
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
    return Settings::first()->otp_type;
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
        return CourierCredential::where( 'vendor_id', $vendorId )->where( 'courier_name', $courierName )->first();
    }
}
