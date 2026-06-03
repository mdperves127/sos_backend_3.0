<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\VendorEmployee;
use App\Models\VendorRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class TenantEmployeeController extends Controller {

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

    public function index() {
        $query = VendorEmployee::query()
            ->latest()
            ->with( 'user:id,name,email,number,uniqid,is_employee,status', 'vendor_role:id,name' );

        if ( Auth::user()->is_employee === null ) {
            $query->where( 'vendor_id', Auth::id() );
        } else {
            $query->where( 'vendor_id', Auth::user()->vendor_id );
        }

        $employees = $query->get();
        $roles     = VendorRole::where( 'vendor_id', vendorId() )->get();

        return response()->json( [
            'status'      => 200,
            'employees'   => $employees,
            'roles'       => $roles,
            'tenant_type' => tenant( 'type' ),
        ] );
    }

    public function create() {
        $roles = VendorRole::where( 'vendor_id', vendorId() )->get();

        return response()->json( [
            'status'      => 200,
            'roles'       => $roles,
            'tenant_type' => tenant( 'type' ),
        ] );
    }

    public function store( Request $request ) {
        if ( $response = $this->assertEmployeeFeatureAllowed() ) {
            return $response;
        }

        $validator = Validator::make( $request->all(), [
            'name'           => 'required|string|max:255',
            'email'          => 'required|string|email|max:255|unique:users',
            'number'         => 'required|numeric|unique:users',
            'status'         => 'required|string|max:20',
            'password'       => 'required|confirmed|min:8',
            'vendor_role_id' => 'required',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        }

        $user                    = new User();
        $user->name              = $request->name;
        $user->email             = $request->email;
        $user->email_verified_at = now();
        $user->role_type         = 'tenant_user';
        $user->number            = $request->number;
        $user->status            = $request->status;
        $user->password          = Hash::make( $request->password );
        $user->uniqid            = uniqid();
        $user->is_employee       = 'yes';
        $user->vendor_id         = vendorId();
        $user->save();

        $employee                 = new VendorEmployee();
        $employee->user_id        = $user->id;
        $employee->vendor_id      = vendorId();
        $employee->vendor_role_id = $request->vendor_role_id;
        $employee->save();

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully employee created',
        ] );
    }

    public function show( $id ) {
        $employees = VendorEmployee::query()
            ->with( ['user:id,name,email,number,uniqid,status'] )
            ->with( ['vendor_role:id,name'] )
            ->where( 'vendor_id', vendorId() )
            ->where( 'id', $id )
            ->first();

        return response()->json( [
            'status'    => 200,
            'employees' => $employees,
        ] );
    }

    public function update( Request $request, $id ) {
        $validator = Validator::make( $request->all(), [
            'name'           => 'required|string|max:255',
            'email'          => 'required|string|email|max:255|unique:users,email,' . $request->user_id,
            'number'         => 'required|string|max:20|unique:users,number,' . $request->user_id,
            'status'         => 'required|string|max:20',
            'vendor_role_id' => 'required',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        }

        $employee = VendorEmployee::where( 'vendor_id', vendorId() )->findOrFail( $id );
        $employee->update( $request->only( [
            'vendor_role_id',
        ] ) );

        $user = User::findOrFail( $employee->user_id );
        $user->update( [
            'name'   => $request->name,
            'email'  => $request->email,
            'number' => $request->number,
            'status' => $request->status,
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully employee updated',
        ] );
    }

    public function delete( $id ) {
        $employee = VendorEmployee::where( 'vendor_id', vendorId() )->find( $id );

        if ( !$employee ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Employee not found !',
            ] );
        }

        $user = User::find( $employee->user_id );

        if ( $user->id == Auth::id() ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'You can\'t delete your self !',
            ] );
        }

        User::find( $employee->user_id )->delete();
        VendorEmployee::where( 'id', $id )->delete();

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully employee deleted',
        ] );
    }

    public function status( $id ) {
        $user = User::where( 'vendor_id', vendorId() )->findOrFail( $id );
        $user->status = $user->status == 'active' ? 'deactive' : 'active';
        $user->save();

        return response()->json( [
            'status'  => 200,
            'message' => $user->status == 'deactive' ? 'Successfully employee deactive' : 'Successfully employee active',
        ] );
    }

    public function permissions() {
        $permission = VendorEmployee::where( 'user_id', Auth::id() )
            ->select( 'id', 'user_id', 'vendor_id', 'vendor_role_id' )
            ->with( 'vendor_role' )
            ->first();
        $isEmployee = User::find( Auth::id() )->is_employee;

        return response()->json( [
            'isEmployee'  => $isEmployee,
            'permission'  => $permission,
            'tenant_type' => tenant( 'type' ),
        ] );
    }

    public function indexRole() {
        $roles = VendorRole::where( 'vendor_id', vendorId() )->get();

        return response()->json( [
            'status'      => 200,
            'roles'       => $roles,
            'tenant_type' => tenant( 'type' ),
        ] );
    }

    public function storeRole( Request $request ) {
        if ( $response = $this->assertEmployeeFeatureAllowed() ) {
            return $response;
        }

        $validator = Validator::make( $request->all(), [
            'name' => 'required|unique:vendor_roles,name,NULL,id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        }

        $data              = $request->all();
        $data['user_id']   = Auth::id();
        $data['vendor_id'] = vendorId();
        VendorRole::create( $data );

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully role created',
        ] );
    }

    public function showRole( $id ) {
        $role = VendorRole::where( 'vendor_id', vendorId() )->findOrFail( $id );

        return response()->json( [
            'status' => 200,
            'role'   => $role,
        ] );
    }

    public function updateRole( Request $request, $id ) {
        if ( $response = $this->assertEmployeeFeatureAllowed() ) {
            return $response;
        }

        $validator = Validator::make( $request->all(), [
            'name' => 'required|unique:vendor_roles,name,' . $id . ',id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        }

        $role = VendorRole::where( 'vendor_id', vendorId() )->findOrFail( $id );
        $data = $request->all();
        $data['user_id']   = Auth::id();
        $data['vendor_id'] = vendorId();
        $role->update( $data );

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully role updated',
        ] );
    }

    public function deleteRole( $id ) {
        VendorRole::where( 'vendor_id', vendorId() )->findOrFail( $id )->delete();

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully role deleted',
        ] );
    }
}
