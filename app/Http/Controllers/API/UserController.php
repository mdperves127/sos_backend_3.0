<?php

namespace App\Http\Controllers\API;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use App\Services\TenantService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Models\Domain;

class UserController extends Controller {

    function updateStatus( Request $request, $id ) {
        $type = request('type');
        $statusRules = $type === 'tenant'
            ? 'required|in:pending,active,blocked'
            : 'required';

        $validator = Validator::make( $request->all(), [
            'status' => $statusRules,
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        } else {

            if ( $type == 'tenant' ) {
                $tenant = Tenant::on('mysql')->find( $id );
                if ( !$tenant ) {
                    return response()->json( [
                        'status'  => 404,
                        'message' => 'Tenant not found',
                    ]);
                }
                $tenant->status = $request->status;
                $tenant->save();
            } else {
                $user = User::on('mysql')->find( $id );
                if ( !$user ) {
                    return response()->json( [
                        'status'  => 404,
                        'message' => 'User not found',
                    ] );
                }
                $user->status = $request->status;
                $user->save();
            }
            // $user         = User::find( $id );
            // $user->status = $request->status;
            // $user->save();

            return response()->json( [
                'status'  => 200,
                'message' => 'Updated successfully!',
            ] );
        }
    }
    public function VendorView( Request $request ) {
        // dd( $request->all() );
        if ( !in_array( request( 'name' ), ['active', 'pending'] ) ) {
            if ( !checkpermission( 'all-vendor' ) ) {
                return $this->permissionmessage();
            }
        }

        if ( request( 'name' ) == 'active' ) {
            if ( !checkpermission( 'active-vendor' ) ) {
                return $this->permissionmessage();
            }
        }

        if ( request( 'name' ) == 'pending' ) {
            if ( !checkpermission( 'pending-vendor' ) ) {
                return $this->permissionmessage();
            }
        }

        $vendor = User::where( 'role_as', '2' )
            ->when( request( 'name' ) == 'pending', function ( $q ) {
                return $q->where( 'status', 'pending' );
            } )
            ->when( request( 'name' ) == 'active', function ( $q ) {
                return $q->where( 'status', 'active' );
            } )

            ->when( request( 'number' ), function ( $q ) {
                return $q->where( 'number', request( 'number' ) );
            } )

            ->when( request( 'email' ), function ( $query, $email ) {
                return $query->where( 'role_as', '2' )
                    ->where( function ( $q ) use ( $email ) {
                        $q->where( 'email', 'LIKE', "%$email%" )
                            ->orWhere( 'number', 'LIKE', "%$email%" )
                            ->orWhere( 'uniqid', 'LIKE', "%$email%" );
                    } );
            } )

            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status' => 200,
            'vendor' => $vendor,
        ] );
    }

    function alluserlist( $routeParam ) {
        // if ( !checkpermission( 'alluser' ) ) {
        //     return $this->permissionmessage();
        // }

        $userTypes = ['vendor', 'affiliate', 'user'];

        // /api/all/user/{vendor|affiliate|user}?status=active|pending
        // /api/all/view/{active|pending}?type=vendor (optional)
        if ( in_array( $routeParam, $userTypes, true ) ) {
            $type   = $routeParam;
            $status = request( 'status' );
        } else {
            $type   = request( 'type' );
            $status = $routeParam;
        }

        $email = request( 'email' );
        $from = request( 'from' );
        $to = request( 'to' );

        $allResults = collect();

        // Get vendors from tenants (type = 'merchant')
        if ( ! $type || $type === 'vendor' ) {
            $vendors = Tenant::where( 'type', 'merchant' )
                ->when( $status === 'active', function ( $q ) {
                    return $q->where( 'status', 'active' );
                } )
                ->when( $status === 'pending', function ( $q ) {
                    return $q->where( 'status', 'pending' );
                } )
                ->when( $status === 'blocked', function ( $q ) {
                    return $q->where( 'status', 'blocked' );
                } )
                ->when( $from != '' && $to != '', function ( $q ) use ( $from, $to ) {
                    return $q->whereBetween( 'created_at', [Carbon::parse( $from ), Carbon::parse( $to )] );
                } )
                ->when( $email, function ( $q ) use ( $email ) {
                    return $q->where( 'email', 'LIKE', '%' . $email . "%" )
                        ->orWhere( 'phone', 'LIKE', '%' . $email . "%" )
                        ->orWhere( 'company_name', 'LIKE', '%' . $email . "%" );
                } )
                ->latest()
                ->get();

            // Transform tenants to user-like format
            $vendors->each( function ( $tenant ) use ( &$allResults ) {
                $allResults->push( (object) [
                    'id'         => $tenant->id,
                    'name'       => $tenant->company_name,
                    'email'      => $tenant->email,
                    'number'     => $tenant->phone,
                    'role_as'    => 2, // vendor
                    'status'     => $tenant->status,
                    'uniqid'     => $tenant->id,
                    'owner_name' => $tenant->owner_name,
                    'address'    => $tenant->address,
                    'balance'    => $tenant->balance ?? 0,
                    'created_at' => $tenant->created_at,
                    'updated_at' => $tenant->updated_at,
                    'is_tenant'  => true,
                    'tenant_type' => 'merchant',
                ] );
            } );
        }

        // Get affiliates from tenants (type = 'dropshipper')
        if ( ! $type || $type === 'affiliate' ) {
            $affiliates = Tenant::where( 'type', 'dropshipper' )
                ->when( $status === 'active', function ( $q ) {
                    return $q->where( 'status', 'active' );
                } )
                ->when( $status === 'pending', function ( $q ) {
                    return $q->where( 'status', 'pending' );
                } )
                ->when( $status === 'blocked', function ( $q ) {
                    return $q->where( 'status', 'blocked' );
                } )
                ->when( $from != '' && $to != '', function ( $q ) use ( $from, $to ) {
                    return $q->whereBetween( 'created_at', [Carbon::parse( $from ), Carbon::parse( $to )] );
                } )
                ->when( $email, function ( $q ) use ( $email ) {
                    return $q->where( 'email', 'LIKE', '%' . $email . "%" )
                        ->orWhere( 'phone', 'LIKE', '%' . $email . "%" )
                        ->orWhere( 'company_name', 'LIKE', '%' . $email . "%" );
                } )
                ->latest()
                ->get();

            // Transform tenants to user-like format
            $affiliates->each( function ( $tenant ) use ( &$allResults ) {
                $allResults->push( (object) [
                    'id'         => $tenant->id,
                    'name'       => $tenant->company_name,
                    'email'      => $tenant->email,
                    'number'     => $tenant->phone,
                    'role_as'    => 3, // affiliate
                    'status'     => $tenant->status,
                    'uniqid'     => $tenant->id,
                    'owner_name' => $tenant->owner_name,
                    'address'    => $tenant->address,
                    'balance'    => $tenant->balance ?? 0,
                    'created_at' => $tenant->created_at,
                    'updated_at' => $tenant->updated_at,
                    'is_tenant'  => true,
                    'tenant_type' => 'dropshipper',
                ] );
            } );
        }

        // Get users from users table (role_as = 4)
        if ( ! $type || $type === 'user' ) {
            $users = User::where( 'role_as', '4' )
                ->when( $status === 'active', function ( $q ) {
                    return $q->where( 'status', 'active' );
                } )
                ->when( $status === 'pending', function ( $q ) {
                    return $q->where( 'status', 'pending' );
                } )
                ->when( $from != '' && $to != '', function ( $q ) use ( $from, $to ) {
                    return $q->whereBetween( 'created_at', [Carbon::parse( $from ), Carbon::parse( $to )] );
                } )
                ->when( $email, function ( $query ) use ( $email ) {
                    return $query->where( 'email', 'LIKE', '%' . $email . "%" )
                        ->orWhere( 'number', 'LIKE', '%' . $email . "%" )
                        ->orWhere( 'uniqid', 'LIKE', '%' . $email . "%" );
                } )
                ->latest()
                ->get();

            // Add users to results
            $users->each( function ( $user ) use ( &$allResults ) {
                $allResults->push( (object) [
                    'id'         => $user->id,
                    'name'       => $user->name,
                    'email'      => $user->email,
                    'number'     => $user->number,
                    'role_as'    => $user->role_as,
                    'status'     => $user->status,
                    'uniqid'     => $user->uniqid,
                    'image'      => $user->image,
                    'balance'    => $user->balance ?? 0,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                    'is_tenant'  => false,
                ] );
            } );
        }

        // Sort by latest
        $allResults = $allResults->sortByDesc( function ( $item ) {
            return $item->created_at ?? '';
        } )->values();

        // Manual pagination
        $page = request()->get( 'page', 1 );
        $perPage = 10;
        $offset = ( $page - 1 ) * $perPage;
        $paginatedResults = $allResults->slice( $offset, $perPage );
        $lastPage = ceil( $allResults->count() / $perPage );

        // Build pagination URLs
        $path = request()->url();
        $queryParams = request()->query();
        $buildUrl = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        // Build links array
        $links = [];
        $links[] = [
            'url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'label' => '&laquo; Previous',
            'active' => false
        ];

        for ( $i = 1; $i <= $lastPage; $i++ ) {
            $links[] = [
                'url' => $buildUrl( $i ),
                'label' => (string) $i,
                'active' => $i == $page
            ];
        }

        $links[] = [
            'url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'label' => 'Next &raquo;',
            'active' => false
        ];

        // Build pagination response
        $response = [
            'data' => $paginatedResults->values(),
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $allResults->count(),
            'last_page' => $lastPage,
            'from' => $offset + 1,
            'to' => min( $offset + $perPage, $allResults->count() ),
            'path' => $path,
            'first_page_url' => $buildUrl( 1 ),
            'last_page_url' => $buildUrl( $lastPage ),
            'prev_page_url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'links' => $links,
        ];

        return response()->json( [
            'status' => 200,
            'all'    => $response,
        ] );
    }

    public function VendorStore( Request $request ) {

        if ( !checkpermission( 'add-vendor' ) ) {
            return $this->permissionmessage();
        }

        $validator = Validator::make( $request->all(), [
            'email'        => 'required|email|unique:tenants,email|max:255',
            'name'         => 'required|string|max:255',
            'number'       => ['required'],
            'status'       => 'required|in:pending,active,blocked',
            'password'     => 'required|string|min:8',
            'balance'      => ['nullable', 'numeric', 'min:0'],
            'domain'       => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]$/'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'owner_name'   => ['nullable', 'string', 'max:255'],
            'address'      => ['nullable', 'string', 'max:500'],
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        }

        $domain = $request->input( 'domain' ) ?: Str::slug( $request->name, '-' );
        $domain = preg_replace( '/[^a-zA-Z0-9-]/', '', $domain ) ?: 'vendor';

        $tenantId = preg_replace( '/[^a-zA-Z0-9]/', '', $domain );
        while ( Tenant::where( 'id', $tenantId )->exists() ) {
            $domain   = $domain . '-' . Str::lower( Str::random( 4 ) );
            $tenantId = preg_replace( '/[^a-zA-Z0-9]/', '', $domain );
        }

        $fullDomain = $domain;
        if ( env( 'APP_ENV' ) === 'local' && $mainDomain = env( 'MAIN_DOMAIN' ) ) {
            if ( ! str_contains( $domain, '.' ) ) {
                $fullDomain = $domain . '.' . $mainDomain;
            }
        }

        if ( Domain::where( 'domain', $fullDomain )->exists() ) {
            return response()->json( [
                'status' => 400,
                'errors' => ['domain' => ['This domain is already registered.']],
            ] );
        }

        try {
            $tenantService = app( TenantService::class );
            $result        = $tenantService->createTenant( [
                'company_name' => $request->input( 'company_name', $request->name ),
                'domain'       => $domain,
                'email'        => $request->email,
                'phone'        => $request->number,
                'address'      => $request->address,
                'owner_name'   => $request->input( 'owner_name', $request->name ),
                'password'     => $request->password,
                'type'         => 'merchant',
                'status'       => $request->status,
            ] );

            $tenant          = $result['tenant'];
            $tenant->balance = $request->balance ?? 0;
            $tenant->save();

            $subscription  = Subscription::find( 1 );
            $amount        = 0;
            $coupon        = null;
            $paymentmethod = 'Manually';
            SubscriptionService::store( $subscription, $tenant, $amount, $coupon?->id, $paymentmethod );

            return response()->json( [
                'status'  => 200,
                'message' => 'Vendor Added Sucessfully',
                'data'    => [
                    'tenant_id'   => $result['tenant_id'],
                    'domain'      => $result['domain_url'],
                    'tenant_type' => 'merchant',
                ],
            ] );
        } catch ( \Exception $e ) {
            return response()->json( [
                'status'  => 500,
                'message' => 'Failed to create vendor tenant',
                'error'   => $e->getMessage(),
            ] );
        }
    }

    public function VendorEdit( $id ) {
        $resolved = $this->resolveVendorEntity( $id );

        if ( ! $resolved['entity'] ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Vendor Id Found',
            ] );
        }

        if ( $resolved['type'] === 'tenant' ) {
            $vendor = $resolved['entity']
                ->load( 'usersubscription.subscription:id,card_heading' );
            $this->mapTenantToVendorShape( $vendor );

            return response()->json( [
                'status'    => 200,
                'vendor'    => $vendor,
                'is_tenant' => true,
            ] );
        }

        $vendor = $resolved['entity']
            ->load( 'usersubscription.subscription:id,card_heading' );

        return response()->json( [
            'status'    => 200,
            'vendor'    => $vendor,
            'is_tenant' => false,
        ] );
    }

    public function UpdateVendor( Request $request, $id ) {
        $validator = Validator::make( $request->all(), [
            'name'   => 'required|max:191',
            'status' => 'required|in:pending,active,blocked',

        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 422,
                'errors' => $validator->messages(),
            ] );
        }

        $resolved = $this->resolveVendorEntity( $id );

        if ( ! $resolved['entity'] ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Vendor Not Found',
            ] );
        }

        if ( $request->balance && $request->balance < 0 ) {
            return response()->json( ['Balance Not Valid'] );
        }

        if ( $resolved['type'] === 'tenant' ) {
            $tenant = $resolved['entity'];
            $tenant->company_name = $request->input( 'company_name', $request->name );
            $tenant->owner_name   = $request->input( 'owner_name', $request->name );
            $tenant->email        = $request->input( 'email', $tenant->email );
            $tenant->phone        = $request->input( 'phone', $request->number );
            $tenant->address      = $request->input( 'address', $tenant->address );
            $tenant->status       = $request->status;

            if ( $request->has( 'balance' ) ) {
                $tenant->balance = $request->balance;
            }

            if ( $request->filled( 'password' ) ) {
                session( ['tenant_password_' . $tenant->id => $request->password] );
            }

            $tenant->save();

            return response()->json( [
                'status'  => 200,
                'message' => 'Vendor Updated Successfully',
            ] );
        }

        $vendor = $resolved['entity'];
        $vendor->name   = $request->input( 'name' );
        $vendor->email  = $request->input( 'email' );
        $vendor->status = $request->input( 'status' );
        $vendor->number = $request->input( 'number' );

        $vendor->balance = $request->input( 'balance' );
        if ( request( 'password' ) ) {
            $vendor->password = bcrypt( request( 'password' ) );
        }

        if ( $request->hasFile( 'image' ) ) {
            $path = $vendor->image;
            if ( File::exists( $path ) ) {
                File::delete( $path );
            }

            $img           = fileUpload( $request->file( 'image' ), 'uploads/vendor', 125, 125 );
            $vendor->image = $img;
        }

        $vendor->update();

        return response()->json( [
            'status'  => 200,
            'message' => 'Vendor Updated Successfully',
        ] );
    }

    private function resolveVendorEntity( $id ): array {
        if ( request( 'type' ) === 'tenant' ) {
            $tenant = Tenant::on( 'mysql' )->find( $id );

            return [
                'type'   => $tenant ? 'tenant' : null,
                'entity' => $tenant,
            ];
        }

        $user = User::on( 'mysql' )->find( $id );
        if ( $user ) {
            return [
                'type'   => 'user',
                'entity' => $user,
            ];
        }

        $tenant = Tenant::on( 'mysql' )->where( 'type', 'merchant' )->find( $id );
        if ( $tenant ) {
            return [
                'type'   => 'tenant',
                'entity' => $tenant,
            ];
        }

        return [
            'type'   => null,
            'entity' => null,
        ];
    }

    private function mapTenantToVendorShape( Tenant $tenant ): void {
        $tenant->setAttribute( 'name', $tenant->company_name );
        $tenant->setAttribute( 'number', $tenant->phone );
        $tenant->setAttribute( 'role_as', 2 );
        $tenant->setAttribute( 'is_tenant', true );
        $tenant->setAttribute( 'tenant_type', 'merchant' );
    }

    public function VendorDelete( $type, $id ) {
        if ( $type == 'tenant' ) {
            $vendor = Tenant::on( 'mysql' )->find( $id );
        } else {
            $vendor = User::on( 'mysql' )->find( $id );
        }

        if ( ! $vendor ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Vendor not found',
            ] );
        }

        $vendor->delete();

        return response()->json( [
            'status'  => 200,
            'message' => 'Vendor Deleted Successfully',
        ] );
    }

    public function AffiliatorView( Request $request ) {
        if ( !in_array( request( 'name' ), ['active', 'pending'] ) ) {
            if ( !checkpermission( 'all-affiliate' ) ) {
                return $this->permissionmessage();
            }
        }

        if ( request( 'name' ) == 'active' ) {
            if ( !checkpermission( 'active-affiliate' ) ) {
                return $this->permissionmessage();
            }
        }

        if ( request( 'name' ) == 'pending' ) {
            if ( !checkpermission( 'pending-affiliate' ) ) {
                return $this->permissionmessage();
            }
        }

        $affiliator = User::where( 'role_as', '3' )
            ->when( request( 'name' ) == 'pending', function ( $q ) {
                return $q->where( 'status', 'pending' );
            } )
            ->when( request( 'name' ) == 'active', function ( $q ) {
                return $q->where( 'status', 'active' );
            } )
            ->when( request( 'number' ), function ( $q ) {
                return $q->where( 'number', request( 'number' ) );
            } )
            ->when( request( 'email' ), function ( $query, $email ) {
                return $query->where( 'role_as', '3' )
                    ->where( 'email', 'LIKE', '%' . $email . "%" )
                    ->orWhere( 'number', 'LIKE', '%' . $email . "%" )
                    ->orWhere( 'uniqid', 'LIKE', '%' . $email . "%" );
            } )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'     => 200,
            'affiliator' => $affiliator,
        ] );
    }

    public function user( Request $request ) {

        if ( !in_array( request( 'name' ), ['active', 'pending'] ) ) {
            if ( !checkpermission( 'all-user' ) ) {
                return $this->permissionmessage();
            }
        }

        if ( request( 'name' ) == 'active' ) {
            if ( !checkpermission( 'active-user' ) ) {
                return $this->permissionmessage();
            }
        }

        if ( request( 'name' ) == 'pending' ) {
            if ( !checkpermission( 'pending-user' ) ) {
                return $this->permissionmessage();
            }
        }

        $user = User::where( 'role_as', '4' )
            ->when( request( 'name' ) == 'pending', function ( $q ) {
                return $q->where( 'status', 'pending' );
            } )
            ->when( request( 'name' ) == 'active', function ( $q ) {
                return $q->where( 'status', 'active' );
            } )
            ->when( request( 'number' ), function ( $q ) {
                return $q->where( 'number', request( 'number' ) );
            } )
            ->when( request( 'email' ), function ( $query, $email ) {
                return $query->where( 'role_as', '4' )
                    ->where( 'email', 'LIKE', '%' . $email . "%" )
                    ->orWhere( 'number', 'LIKE', '%' . $email . "%" )
                    ->orWhere( 'uniqid', 'LIKE', '%' . $email . "%" )
                    ->where( 'role_as', '4' );
            } )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status' => 200,
            'user'   => $user,
        ] );
    }

    public function UserStore( Request $request ) {
        if ( !checkpermission( 'add-user' ) ) {
            return $this->permissionmessage();
        }

        $validator = Validator::make( $request->all(), [
            'email'    => 'required|unique:users|max:255',
            'name'     => 'required',
            'number'   => 'required',
            'status'   => 'required',
            'password' => 'required:min:6',

        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        } else {
            $affiliator                    = new User();
            $affiliator->name              = $request->input( 'name' );
            $affiliator->email             = $request->input( 'email' );
            $affiliator->password          = Hash::make( $request['password'] );
            $affiliator->status            = $request->input( 'status' );
            $affiliator->number            = $request->input( 'number' );
            $affiliator->role_as           = '4';
            $affiliator->uniqid            = uniqid();
            $affiliator->email_verified_at = $request->verified_at == 1 ? now() : NULL;

            if ( $request->hasFile( 'image' ) ) {

                $img = fileUpload( $request->file( 'image' ), 'uploads/affiliator', 125, 125 );

                $affiliator->image = $img;
            }

            $affiliator->save();
            return response()->json( [
                'status'  => 200,
                'message' => 'User Added Sucessfully',
            ] );
        }
    }

    public function AffiliatorStore( Request $request ) {
        if ( !checkpermission( 'add-affiliate' ) ) {
            return $this->permissionmessage();
        }

        $validator = Validator::make( $request->all(), [
            'email'        => 'required|email|unique:tenants,email|max:255',
            'name'         => 'required|string|max:255',
            'number'       => ['required', 'numeric'],
            'status'       => 'required|in:pending,active,blocked',
            'password'     => 'required|string|min:6',
            'domain'       => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]$/'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'owner_name'   => ['nullable', 'string', 'max:255'],
            'address'      => ['nullable', 'string', 'max:500'],
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        }

        $domain = $request->input( 'domain' ) ?: Str::slug( $request->name, '-' );
        $domain = preg_replace( '/[^a-zA-Z0-9-]/', '', $domain ) ?: 'affiliate';

        $tenantId = preg_replace( '/[^a-zA-Z0-9]/', '', $domain );
        while ( Tenant::where( 'id', $tenantId )->exists() ) {
            $domain   = $domain . '-' . Str::lower( Str::random( 4 ) );
            $tenantId = preg_replace( '/[^a-zA-Z0-9]/', '', $domain );
        }

        $fullDomain = $domain;
        if ( env( 'APP_ENV' ) === 'local' && $mainDomain = env( 'MAIN_DOMAIN' ) ) {
            if ( ! str_contains( $domain, '.' ) ) {
                $fullDomain = $domain . '.' . $mainDomain;
            }
        }

        if ( Domain::where( 'domain', $fullDomain )->exists() ) {
            return response()->json( [
                'status' => 400,
                'errors' => ['domain' => ['This domain is already registered.']],
            ] );
        }

        try {
            $tenantService = app( TenantService::class );
            $result        = $tenantService->createTenant( [
                'company_name' => $request->input( 'company_name', $request->name ),
                'domain'       => $domain,
                'email'        => $request->email,
                'phone'        => $request->number,
                'address'      => $request->address,
                'owner_name'   => $request->input( 'owner_name', $request->name ),
                'password'     => $request->password,
                'type'         => 'dropshipper',
                'status'       => $request->status,
            ] );

            $tenant          = $result['tenant'];
            $tenant->balance = 0;
            $tenant->save();

            $subscription  = Subscription::find( 13 );
            $amount        = 0;
            $coupon        = null;
            $paymentmethod = 'Manually';
            SubscriptionService::store( $subscription, $tenant, $amount, $coupon?->id, $paymentmethod );

            return response()->json( [
                'status'  => 200,
                'message' => 'Affiliator Added Sucessfully',
                'data'    => [
                    'tenant_id'   => $result['tenant_id'],
                    'domain'      => $result['domain_url'],
                    'tenant_type' => 'dropshipper',
                ],
            ] );
        } catch ( \Exception $e ) {
            return response()->json( [
                'status'  => 500,
                'message' => 'Failed to create affiliate tenant',
                'error'   => $e->getMessage(),
            ] );
        }
    }
    public function UserEdit( $id ) {
        $type = request('type');
        if ( $type == 'tenant' ) {
            $tenant = Tenant::on('mysql')->find( $id );
            if ( $tenant ) {
                return response()->json( [
                    'status' => 200,
                    'user' => $tenant,
                ] );
            } else {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'No Tenant Id Found',
                ] );
            }
        } else {
            $user = User::on('mysql')->find( $id );
            if ( $user ) {
                return response()->json( [
                    'status' => 200,
                    'user'   => $user,
                ] );
            } else {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'No User Id Found',
                ] );
            }
        }
    }

    public function AffiliatorEdit( $id ) {
        $affiliator = User::find( $id )->load( 'usersubscription.subscription:id,card_heading' );

        if ( $affiliator ) {
            return response()->json( [
                'status'     => 200,
                'affiliator' => $affiliator,
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Affiliator Id Found',
            ] );
        }
    }

    public function UpdateAffiliator( Request $request, $id ) {
        $validator = Validator::make( $request->all(), [
            'name'    => 'required|max:191',
            'status'  => 'required|max:191',
            'balance' => ['numeric'],
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 422,
                'errors' => $validator->messages(),
            ] );
        } else {
            $affiliator = User::find( $id );
            if ( $affiliator ) {

                $affiliator->name    = $request->input( 'name' );
                $affiliator->email   = $request->input( 'email' );
                $affiliator->status  = $request->input( 'status' );
                $affiliator->number  = $request->input( 'number' );
                $affiliator->balance = $request->input( 'balance' );

                if ( request( 'password' ) ) {
                    $affiliator->password = bcrypt( request( 'password' ) );
                }

                if ( $request->hasFile( 'image' ) ) {
                    $path = $affiliator->image;
                    if ( File::exists( $path ) ) {
                        File::delete( $path );
                    }

                    $img = fileUpload( $request->file( 'image' ), 'uploads/affiliator', 125, 125 );

                    $affiliator->image = $img;
                }

                $affiliator->update();

                return response()->json( [
                    'status'  => 200,
                    'message' => 'Affiliator Updated Successfully',
                ] );
            } else {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'Affiliator Not Found',
                ] );
            }
        }
    }

    public function Updateuser( Request $request, $id ) {

        $validator = Validator::make( $request->all(), [
            'name'    => 'required|max:191',
            'status'  => 'required|max:191',
            'email'   => 'required',
            'number'  => 'required',
            'balance' => ['required', 'numeric'],
            'type' => 'required|in:user,tenant',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 422,
                'errors' => $validator->messages(),
            ] );
        } else {
            if($request->type == 'tenant'){
                $tenant = Tenant::find( $id );
                if ( $tenant ) {
                    $tenant->company_name    = $request->input( 'company_name' );
                    $tenant->email   = $request->input( 'email' );
                    $tenant->owner_name  = $request->input( 'owner_name' );
                    $tenant->phone  = $request->input( 'phone' );
                    $tenant->address  = $request->input( 'address' );
                    $tenant->balance = request( 'balance' );
                }
                else {
                    return response()->json( [
                        'status'  => 404,
                        'message' => 'Tenant Not Found',
                    ] );
                }
                $tenant->update();

                return response()->json( [
                    'status'  => 200,
                    'message' => 'Tenant Updated Successfully',
                ] );
            }
            else {
                $user = User::find( $id );
                if ( $user ) {

                    $user->name    = $request->input( 'name' );
                    $user->email   = $request->input( 'email' );
                    $user->status  = $request->input( 'status' );
                    $user->number  = $request->input( 'number' );
                    $user->balance = request( 'balance' );

                    if ( $request->hasFile( 'image' ) ) {
                        $path = $user->image;
                        if ( File::exists( $path ) ) {
                            File::delete( $path );
                        }

                        $img = fileUpload( $request->file( 'image' ), 'uploads/affiliator', 125, 125 );

                        $user->image = $img;
                    }

                    $user->update();

                    return response()->json( [
                        'status'  => 200,
                        'message' => 'Affiliator Updated Successfully',
                    ] );
                } else {
                    return response()->json( [
                        'status'  => 404,
                        'message' => 'Affiliator Not Found',
                    ] );
                }
            }
        }
    }
    public function UserDelete( $id ) {
        $vendor = User::find( $id );

        if ( $vendor ) {
            $vendor->delete();
            return response()->json( [
                'status'  => 200,
                'message' => 'User Deleted Successfully',
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'No User ID Found',
            ] );
        }
    }

    public function AffiliatorDelete( $id ) {
        $vendor = User::find( $id );

        // if ($vendor->image) {
        //  unlink('uploads/vendor/'.$vendor->image);
        //   }

        if ( $vendor ) {
            $vendor->delete();
            return response()->json( [
                'status'  => 200,
                'message' => 'vendor Deleted Successfully',
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Vendor ID Found',
            ] );
        }
    }

    public function subscriptionList() {

        $subscriptionList = UserSubscription::latest()
            ->with( 'subscription:id,subscription_user_type,subscription_package_type,card_heading,plan_type', 'user:id,name,email' )
            ->when( request( 'from' ) && request( 'to' ), function ( $query ) {
                $fromDate = Carbon::parse( request( 'from' ) );
                $toDate   = Carbon::parse( request( 'to' ) )->addDay( 1 );
                $query->whereBetween( 'created_at', [$fromDate, $toDate] );
            } )
            ->get();
        return response()->json( [
            'status'           => 200,
            'subscriptionList' => $subscriptionList,
        ] );
    }
}
