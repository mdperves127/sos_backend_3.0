<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\VendorRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class TenantEmployeeController extends Controller {

    private function employeeQuery() {
        return User::query()
            ->where( 'role_type', 'employee' )
            ->whereHas( 'vendorRole', function ( $query ) {
                $query->where( 'vendor_id', tenantOwnerId() );
            } );
    }

    private function tenantSubscription(): ?UserSubscription {
        if ( !function_exists( 'tenant' ) || !tenant() ) {
            return null;
        }

        return UserSubscription::on( 'mysql' )
            ->where( 'tenant_id', tenant()->id )
            ->first();
    }

    private function assertEmployeeFeatureAllowed() {
        $subscription = $this->tenantSubscription();

        if ( !$subscription ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! It seems you are not eligible to access this feature. Please contact the administrator for assistance.',
            ] );
        }

        if ( $subscription->employee_create == null ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! This service is not available with your current subscription. Please contact the administrator for assistance.',
            ] );
        }

        return null;
    }

    private function assertAdmin(): ?\Illuminate\Http\JsonResponse {
        if ( !isTenantAdmin() ) {
            return response()->json( [
                'status'  => 403,
                'message' => 'Only tenant admin can perform this action.',
            ], 403 );
        }

        return null;
    }

    private function roleBelongsToOwner( int $roleId ): bool {
        return VendorRole::where( 'id', $roleId )
            ->where( 'vendor_id', tenantOwnerId() )
            ->exists();
    }

    public function index() {
        if ( $denied = $this->assertAdmin() ) {
            return $denied;
        }

        $employees = $this->employeeQuery()
            ->latest()
            ->with( 'vendorRole:id,name,vendor_id' )
            ->get( ['id', 'name', 'email', 'number', 'uniqid', 'status', 'role_type', 'vendor_role_id'] );

        $roles = VendorRole::where( 'vendor_id', tenantOwnerId() )->get();

        return response()->json( [
            'status'      => 200,
            'employees'   => $employees,
            'roles'       => $roles,
            'tenant_type' => tenant( 'type' ),
        ] );
    }

    public function create() {
        if ( $denied = $this->assertAdmin() ) {
            return $denied;
        }

        $roles = VendorRole::where( 'vendor_id', tenantOwnerId() )->get();

        return response()->json( [
            'status'      => 200,
            'roles'       => $roles,
            'tenant_type' => tenant( 'type' ),
        ] );
    }

    public function store( Request $request ) {
        if ( $denied = $this->assertAdmin() ) {
            return $denied;
        }
        if ( $response = $this->assertEmployeeFeatureAllowed() ) {
            return $response;
        }

        $validator = Validator::make( $request->all(), [
            'name'           => 'required|string|max:255',
            'email'          => 'required|string|email|max:255|unique:users',
            'number'         => 'required|numeric|unique:users',
            'status'         => 'required|string|max:20',
            'password'       => 'required|confirmed|min:8',
            'vendor_role_id' => 'required|integer',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        }

        if ( !$this->roleBelongsToOwner( (int) $request->vendor_role_id ) ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Invalid role for this tenant.',
            ] );
        }

        $user                    = new User();
        $user->name              = $request->name;
        $user->email             = $request->email;
        $user->email_verified_at = now();
        $user->role_type         = 'employee';
        $user->vendor_role_id    = $request->vendor_role_id;
        $user->number            = $request->number;
        $user->status            = $request->status;
        $user->password          = Hash::make( $request->password );
        $user->uniqid            = uniqid();
        $user->save();

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully employee created',
        ] );
    }

    public function show( $id ) {
        if ( $denied = $this->assertAdmin() ) {
            return $denied;
        }

        $employee = $this->employeeQuery()
            ->with( 'vendorRole' )
            ->where( 'id', $id )
            ->first();

        if ( !$employee ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Employee not found.',
            ] );
        }

        return response()->json( [
            'status'   => 200,
            'employee' => $employee,
        ] );
    }

    public function update( Request $request, $id ) {
        if ( $denied = $this->assertAdmin() ) {
            return $denied;
        }

        $validator = Validator::make( $request->all(), [
            'name'           => 'required|string|max:255',
            'email'          => 'required|string|email|max:255|unique:users,email,' . $id,
            'number'         => 'required|string|max:20|unique:users,number,' . $id,
            'status'         => 'required|string|max:20',
            'vendor_role_id' => 'required|integer',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        }

        if ( !$this->roleBelongsToOwner( (int) $request->vendor_role_id ) ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Invalid role for this tenant.',
            ] );
        }

        $employee = $this->employeeQuery()->findOrFail( $id );
        $employee->update( [
            'name'           => $request->name,
            'email'          => $request->email,
            'number'         => $request->number,
            'status'         => $request->status,
            'vendor_role_id' => $request->vendor_role_id,
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully employee updated',
        ] );
    }

    public function delete( $id ) {
        if ( $denied = $this->assertAdmin() ) {
            return $denied;
        }

        $employee = $this->employeeQuery()->find( $id );

        if ( !$employee ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Employee not found !',
            ] );
        }

        if ( $employee->id == Auth::id() ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'You can\'t delete your self !',
            ] );
        }

        $employee->delete();

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully employee deleted',
        ] );
    }

    public function status( $id ) {
        if ( $denied = $this->assertAdmin() ) {
            return $denied;
        }

        $user         = $this->employeeQuery()->findOrFail( $id );
        $user->status = $user->status == 'active' ? 'deactive' : 'active';
        $user->save();

        return response()->json( [
            'status'  => 200,
            'message' => $user->status == 'deactive' ? 'Successfully employee deactive' : 'Successfully employee active',
        ] );
    }

    public function permissions() {
        $user = User::with( 'vendorRole' )->find( Auth::id() );

        if ( $user->role_type === 'admin' ) {
            return response()->json( [
                'role_type'   => 'admin',
                'permissions' => 'all',
                'tenant_type' => tenant( 'type' ),
            ] );
        }

        if ( $user->role_type !== 'employee' || !$user->vendorRole ) {
            return response()->json( [
                'role_type'   => $user->role_type,
                'permissions' => null,
                'tenant_type' => tenant( 'type' ),
            ] );
        }

        return response()->json( [
            'role_type'   => 'employee',
            'role'        => $user->vendorRole,
            'permissions' => $user->vendorRole,
            'tenant_type' => tenant( 'type' ),
        ] );
    }

    public function indexRole() {
        if ( !isTenantAdmin() && Auth::user()->role_type !== 'employee' ) {
            return response()->json( ['status' => 403, 'message' => 'Access denied.'], 403 );
        }

        $roles = VendorRole::where( 'vendor_id', tenantOwnerId() )->get();

        return response()->json( [
            'status'      => 200,
            'roles'       => $roles,
            'tenant_type' => tenant( 'type' ),
        ] );
    }

    public function storeRole( Request $request ) {
        if ( $denied = $this->assertAdmin() ) {
            return $denied;
        }
        if ( $response = $this->assertEmployeeFeatureAllowed() ) {
            return $response;
        }

        $validator = Validator::make( $request->all(), [
            'name' => 'required|unique:vendor_roles,name,NULL,id,vendor_id,' . tenantOwnerId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        }

        $data              = $request->all();
        $data['user_id']   = Auth::id();
        $data['vendor_id'] = tenantOwnerId();
        VendorRole::create( $data );

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully role created',
        ] );
    }

    public function showRole( $id ) {
        $role = VendorRole::where( 'vendor_id', tenantOwnerId() )->findOrFail( $id );

        return response()->json( [
            'status' => 200,
            'role'   => $role,
        ] );
    }

    public function updateRole( Request $request, $id ) {
        if ( $denied = $this->assertAdmin() ) {
            return $denied;
        }
        if ( $response = $this->assertEmployeeFeatureAllowed() ) {
            return $response;
        }

        $validator = Validator::make( $request->all(), [
            'name' => 'required|unique:vendor_roles,name,' . $id . ',id,vendor_id,' . tenantOwnerId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        }

        $role = VendorRole::where( 'vendor_id', tenantOwnerId() )->findOrFail( $id );
        $data = $request->all();
        $data['user_id']   = Auth::id();
        $data['vendor_id'] = tenantOwnerId();
        $role->update( $data );

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully role updated',
        ] );
    }

    public function deleteRole( $id ) {
        if ( $denied = $this->assertAdmin() ) {
            return $denied;
        }

        $inUse = User::where( 'role_type', 'employee' )
            ->where( 'vendor_role_id', $id )
            ->exists();

        if ( $inUse ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Role is assigned to employees. Reassign or remove employees first.',
            ] );
        }

        VendorRole::where( 'vendor_id', tenantOwnerId() )->findOrFail( $id )->delete();

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully role deleted',
        ] );
    }
}
