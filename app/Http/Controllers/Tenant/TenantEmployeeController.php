<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\VendorRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class TenantEmployeeController extends Controller {

    private function assertEmployeeSchema(): ?\Illuminate\Http\JsonResponse {
        if ( !Schema::hasColumn( 'users', 'vendor_role_id' ) ) {
            return response()->json( [
                'status'  => 500,
                'message' => 'Employee module requires a database update. Please run: php artisan tenants:migrate',
            ], 500 );
        }

        return null;
    }

    private function employeeSelectColumns(): array {
        $columns = ['id', 'name', 'email', 'uniqid', 'status', 'role_type'];
        if ( Schema::hasColumn( 'users', 'number' ) ) {
            $columns[] = 'number';
        }
        if ( Schema::hasColumn( 'users', 'vendor_role_id' ) ) {
            $columns[] = 'vendor_role_id';
        }

        return $columns;
    }

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

    /** @return list<string> */
    private function vendorRolePermissionKeys(): array {
        return [
            'products', 'add_product', 'all_product', 'active_product', 'pending_product',
            'edit_product', 'reject_product', 'warehouse', 'unit', 'color', 'variation',
            'order', 'add_order', 'all_order', 'hold_order', 'pending_order', 'receive_order',
            'delivery_processing', 'delivery_order', 'cancel_order', 'customer',
            'pos_sale', 'add_pos_sale', 'all_pos_sale', 'payment_history_pos_sale',
            'supplier', 'purchase', 'add_purchase', 'all_purchase', 'payment_history_purchase',
            'barcode', 'barcode_generate', 'barcode_manage', 'setting', 'source', 'payment_method',
            'affiliate_request', 'all_request', 'active_request', 'pending_request', 'reject_request',
            'expired_request', 'return_list', 'purchase_return', 'sale_return', 'add_wastage', 'all_wastage',
            'report', 'stock_report', 'sales_report', 'due_sales_report', 'purchase_report', 'warehouse_report',
            'service_and_order', 'create_service', 'all_service', 'service_order',
            'coupon', 'membership', 'advertiser', 'purchase_service', 'all_service_order',
            'pending_service_order', 'progress_service_order', 'hold_service_order', 'cancel_service_order',
            'balance', 'recharge', 'withdraw', 'recharge_history', 'create_support', 'all_support',
            'chat', 'employee', 'delivery_company', 'delivery_area', 'pickup_area',
            'stock_shortage_report', 'top_repeat_customer', 'sales_report_daily',
        ];
    }

    private function normalizeRolePermissionValue( mixed $value ): ?int {
        if ( is_array( $value ) ) {
            return !empty( $value ) ? 1 : 0;
        }
        if ( $value === null || $value === '' ) {
            return null;
        }

        return (int) $value ? 1 : 0;
    }

    private function buildVendorRolePayload( Request $request ): array {
        $name = $request->input( 'name' );
        if ( is_array( $name ) ) {
            $name = reset( $name );
        }

        $data = [
            'name' => (string) $name,
        ];

        $permissionSources = array_filter( [
            is_array( $request->input( 'permissions' ) ) ? $request->input( 'permissions' ) : null,
            $request->except( ['name', 'permissions', '_token', '_method'] ),
        ] );

        foreach ( $this->vendorRolePermissionKeys() as $key ) {
            foreach ( $permissionSources as $source ) {
                if ( !is_array( $source ) || !array_key_exists( $key, $source ) ) {
                    continue;
                }
                $data[$key] = $this->normalizeRolePermissionValue( $source[$key] );
                break;
            }
        }

        if ( $request->has( 'invoice_generate' ) ) {
            $invoice = $request->input( 'invoice_generate' );
            $data['invoice_generate'] = is_array( $invoice )
                ? ( !empty( $invoice ) ? '1' : '0' )
                : (string) $invoice;
        }

        return $data;
    }

    public function index() {
        if ( $denied = $this->assertAdmin() ) {
            return $denied;
        }
        if ( $schema = $this->assertEmployeeSchema() ) {
            return $schema;
        }

        $employees = $this->employeeQuery()
            ->latest()
            ->with( 'vendorRole:id,name,vendor_id' )
            ->get( $this->employeeSelectColumns() );

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
        if ( $schema = $this->assertEmployeeSchema() ) {
            return $schema;
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
        if ( $schema = $this->assertEmployeeSchema() ) {
            return $schema;
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
        if ( $schema = $this->assertEmployeeSchema() ) {
            return $schema;
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
        if ( $schema = $this->assertEmployeeSchema() ) {
            return $schema;
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
        if ( $schema = $this->assertEmployeeSchema() ) {
            return $schema;
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
        if ( $schema = $this->assertEmployeeSchema() ) {
            return $schema;
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
        if ( $schema = $this->assertEmployeeSchema() ) {
            return $schema;
        }

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
            'name' => 'required|string|max:255|unique:vendor_roles,name,NULL,id,vendor_id,' . tenantOwnerId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        }

        $data              = $this->buildVendorRolePayload( $request );
        $data['user_id']   = (string) Auth::id();
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
            'name' => 'required|string|max:255|unique:vendor_roles,name,' . $id . ',id,vendor_id,' . tenantOwnerId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        }

        $role = VendorRole::where( 'vendor_id', tenantOwnerId() )->findOrFail( $id );
        $data = $this->buildVendorRolePayload( $request );
        $data['user_id']   = (string) Auth::id();
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
