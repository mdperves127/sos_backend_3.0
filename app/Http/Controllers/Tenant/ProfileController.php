<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tenant;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\CmsSetting;
use App\Models\TenantCustomDomain;
use App\Models\TenantInstalledAddon;
use App\Models\Product;
use App\Models\ProductDetails;
use App\Models\PosSales;
use App\Models\VendorService;
use App\Services\CustomDomainService;
use Illuminate\Support\Facades\Schema;

class ProfileController extends Controller
{
    public function TenantProfile()
    {
        $user = User::where('id', Auth::user()->id)->first();

        $usersubscription = UserSubscription::on('mysql')
        ->where('tenant_id', tenant()->id)
        ->with('subscription:id,card_heading')->first();

        $cmsSetting = CmsSetting::on('tenant')->first(['theme']);

        $usage = $this->tenantSegmentUsage( $usersubscription );

        $addons = TenantInstalledAddon::on( 'mysql' )
            ->with( [ 'addon.features' ] )
            ->where( 'tenant_id', tenant()->id )
            ->where( 'status', 'active' )
            ->latest( 'activated_at' )
            ->get()
            ->flatMap( function ( TenantInstalledAddon $installation ) {
                return $installation->addon?->features ?? collect();
            } )
            ->map( function ( $feature ) use ( $usage ) {
                $key = strtolower( trim( (string) $feature->key ) );

                $row = [
                    'key'        => $feature->key,
                    'value'      => $feature->value,
                    'visibility' => $feature->visibility,
                ];

                if ( isset( $usage[ $key ] ) ) {
                    $row['used']  = $usage[ $key ]['used'];
                    $row['limit'] = $usage[ $key ]['limit'];
                }

                return $row;
            } )
            ->values();

        return response()->json([
            'status' => 200,
            'user' => $user,
            'usersubscription' => $usersubscription,
            'cms_setting' => $cmsSetting,
            'addons' => $addons,
            'usage' => $usage,
        ]);
    }

    /**
     * Current tenant usage vs package limits for subscription/addon segments.
     *
     * @return array<string, array{used: int, limit: int|null}>
     */
    private function tenantSegmentUsage( ?UserSubscription $subscription ): array
    {
        $ownerId = vendorId();
        $userId  = Auth::id();

        $productQtyUsed = 0;
        $productRequestUsed = 0;
        $productApproveUsed = 0;
        $affiliateRequestUsed = 0;
        $posSaleQtyUsed = 0;

        try {
            $productQtyUsed = Product::on( 'tenant' )->count();
            $productRequestUsed = ProductDetails::on( 'tenant' )
                ->when( $userId, fn ( $q ) => $q->where( 'user_id', $userId ) )
                ->count();
            $productApproveUsed = ProductDetails::on( 'tenant' )
                ->when( $userId, fn ( $q ) => $q->where( 'user_id', $userId ) )
                ->where( 'status', 1 )
                ->count();
            $affiliateRequestUsed = ProductDetails::on( 'tenant' )
                ->when( $ownerId, fn ( $q ) => $q->where( 'vendor_id', $ownerId ) )
                ->where( 'status', 1 )
                ->count();

            if ( Schema::connection( 'tenant' )->hasTable( 'pos_sales' ) ) {
                $posSaleQtyUsed = PosSales::on( 'tenant' )
                    ->when( $ownerId, fn ( $q ) => $q->where( 'vendor_id', $ownerId ) )
                    ->count();
            }
        } catch ( \Throwable $e ) {
            // Tenant tables may be unavailable during partial setup.
        }

        $serviceCreateUsed = VendorService::on( 'mysql' )
            ->where( 'tenant_id', tenant()->id )
            ->count();

        $addonBonus = $this->addonFeatureBonuses( (string) tenant()->id );

        $limit = function ( string $field ) use ( $subscription, $addonBonus ): ?int {
            if ( ! $subscription ) {
                return $addonBonus[ $field ] ?? null;
            }

            $base = $subscription->{$field} ?? null;
            $base = $base === null ? null : (int) $base;
            $bonus = (int) ( $addonBonus[ $field ] ?? 0 );

            if ( $base === null && $bonus === 0 ) {
                return null;
            }

            return ( $base ?? 0 ) + $bonus;
        };

        return [
            'website_visits'    => [
                'used'  => (int) ( $subscription?->already_visits ?? 0 ),
                'limit' => $limit( 'website_visits' ),
            ],
            'product_request'   => [
                'used'  => $productRequestUsed,
                'limit' => $limit( 'product_request' ),
            ],
            'product_approve'   => [
                'used'  => $productApproveUsed,
                'limit' => $limit( 'product_approve' ),
            ],
            'product_qty'       => [
                'used'  => $productQtyUsed,
                'limit' => $limit( 'product_qty' ),
            ],
            'service_create'    => [
                'used'  => $serviceCreateUsed,
                'limit' => $limit( 'service_create' ),
            ],
            'pos_sale_qty'      => [
                'used'  => $posSaleQtyUsed,
                'limit' => $limit( 'pos_sale_qty' ),
            ],
            'affiliate_request' => [
                'used'  => $affiliateRequestUsed,
                'limit' => $limit( 'affiliate_request' ),
            ],
        ];
    }

    /**
     * Sum numeric feature values from active addons for known segment keys.
     *
     * @return array<string, int>
     */
    private function addonFeatureBonuses( string $tenantId ): array
    {
        $keys = [
            'website_visits',
            'product_request',
            'product_approve',
            'product_qty',
            'service_create',
            'pos_sale_qty',
            'affiliate_request',
        ];

        $bonuses = array_fill_keys( $keys, 0 );

        $installations = TenantInstalledAddon::on( 'mysql' )
            ->with( [ 'addon.features' ] )
            ->where( 'tenant_id', $tenantId )
            ->where( 'status', 'active' )
            ->get();

        foreach ( $installations as $installation ) {
            foreach ( $installation->addon?->features ?? [] as $feature ) {
                $key = strtolower( trim( (string) $feature->key ) );
                if ( ! array_key_exists( $key, $bonuses ) ) {
                    continue;
                }
                if ( is_numeric( $feature->value ) ) {
                    $bonuses[ $key ] += max( 0, (int) $feature->value );
                }
            }
        }

        return $bonuses;
    }

    public function TenantUpdateProfile(Request $request)
    {
        $validator =  Validator::make($request->all(), [
            'name' => 'required',
            'old_password' => 'nullable',
            'new_password' => 'nullable',
        ]);

        if ($request->has('old_password') && $request->input('old_password') !== null) {
            $validator->addRules([
                'new_password' => 'required|min:8|max:32',
            ]);
        }

        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'message' => $validator->messages()
            ]);
        }


        $data = User::find(Auth::user()->id);
        $data->name = $request->name;

        if($request->has('new_password') && $request->has('old_password')) {
            if($request->new_password == $request->old_password) {
                return response()->json([
                    'status' => 400,
                    'message' => 'New Password and Old Password cannot be the same!'
                ]);
            }
        }

        if ($request->has('old_password')) {
            if (!Hash::check($request->old_password, $data->password)) {
                return response()->json([
                    'status' => 400,
                    'message' => 'Old Password Not Match!'
                ]);
            } else {
                $data->password = bcrypt($request->new_password);
            }
        }


        // if ($request->hasFile('image')) {
        //     if (File::exists($data->image)) {
        //         File::delete($data->image);
        //     }
        //     $image =  fileUpload($request->image, 'uploads/vendor');
        //     $data->image = $image;
        // }

        $data->save();
        return response()->json([
            'status' => 200,
            'message' => 'Profile updated Sucessfully!',
            'user' => $data,
            'tenant_type' => Tenant::on('mysql')->where('id', tenant()->id)->first()->type,
        ]);
    }

    public function shopInfo()
    {
        $tenant = Tenant::on('mysql')->where('id', tenant()->id)->first();
        $domainRecord = TenantCustomDomain::on('mysql')->where('tenant_id', tenant()->id)->first();
        $targetIp = app(CustomDomainService::class)->targetIp();
        $package = UserSubscription::on('mysql')
            ->where('tenant_id', tenant()->id)
            ->latest('id')
            ->first();

        return response()->json([
            'status' => 200,
            'shop_info' => $tenant,
            'has_custom_domain' => $package?->has_custom_domain ?? 'no',
            'custom_domain_connection' => $domainRecord ? [
                'domain' => $domainRecord->domain,
                'status' => $domainRecord->status,
                'verification' => $domainRecord->verification,
                'ssl' => $domainRecord->ssl,
                'target_ip' => $domainRecord->target_ip,
                'verified_at' => $domainRecord->verified_at,
                'activated_at' => $domainRecord->activated_at,
                'last_dns_check' => $domainRecord->last_dns_check,
            ] : null,
            'dns_instructions' => [
                'type' => 'A',
                'host' => '@',
                'value' => $targetIp,
            ],
        ]);
    }

    public function shopInfoUpdate(Request $request)
    {

        $tenant = Tenant::on('mysql')->where('id', tenant()->id)->first();


        $validator = Validator::make($request->all(), [
            'company_name' => 'required',
            'owner_name' => 'required',
            'phone' => 'required|unique:mysql.tenants,phone,' . $tenant->id,
            'address' => 'required',
            'custom_domain' => 'nullable|string|max:255|unique:mysql.tenants,custom_domain,' . $tenant->id,
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'validation_errors' => $validator->messages(),
            ]);
        }


        $updateData = [
            'company_name' => $request->company_name,
            'owner_name'   => $request->owner_name,
            'phone'        => $request->phone,
            'address'      => $request->address,
        ];

        if ( $request->filled( 'custom_domain' ) ) {
            $package = UserSubscription::on('mysql')
                ->where('tenant_id', tenant()->id)
                ->latest('id')
                ->first();

            if ( ( $package?->has_custom_domain ?? 'no' ) !== 'yes' ) {
                return response()->json( [
                    'status'  => 403,
                    'message' => 'Your package does not include custom domain.',
                ], 403 );
            }

            $updateData['custom_domain'] = $request->custom_domain;
        }

        Tenant::on('mysql')->where('id', tenant()->id)->update( $updateData );

        return response()->json([
            "status" => 200,
            "message" => 'Sucessfully update',
        ]);
    }
}
