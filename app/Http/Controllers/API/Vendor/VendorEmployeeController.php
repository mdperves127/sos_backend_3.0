<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VendorEmployee;
use App\Models\VendorRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class VendorEmployeeController extends Controller {

    public function index() {
        // Define the base query for fetching employees
        $query = VendorEmployee::query()
            ->latest()
            ->with( 'user:id,name,email,number,uniqid,is_employee,status', 'vendor_role:id,name' );

        // Filter employees based on user type
        if ( Auth::user()->is_employee === null ) {
            $query->where( 'vendor_id', Auth::id() );
        } else {
            $query->where( 'vendor_id', Auth::user()->vendor_id );
        }

        // Fetch employees
        $employees = $query->get();
        $roles     = VendorRole::where( 'vendor_id', vendorId() )->get();

        // Return the response
        return response()->json( [
            'status'    => 200,
            'employees' => $employees,
            'roles'     => $roles,
        ] );
    }

    public function create() {
        $roles = VendorRole::where( 'vendor_id', vendorId() )->get();
        return response()->json( [
            "status" => 200,
            "roles"  => $roles,
        ] );
    }

    public function store( Request $request ) {

        $user = User::find( vendorId() );

        if ( !$user->usersubscription ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! It seems you are not eligible to access this feature. Please contact the administrator for assistance.',
            ] );
        }

        if ( $user?->usersubscription?->employee_create == null ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! This service is not available with your current subscription. Please contact the administrator for assistance.',
            ] );
        }
        $validator = Validator::make( $request->all(), [
            'name'           => 'required|string|max:255',
            'email'          => 'required|string|email|max:255|unique:users',
            'number'         => 'required|numeric|unique:users',
            'status'         => 'required|string|max:20',
            'password'       => 'required|confirmed|min:8',
            'vendor_role_id' => 'required',
        ] );

        // Check if the validation fails
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
        $user->role_as           = 2;
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
            ->where( 'vendor_id', Auth::id() )->where( 'id', $id )->first();
        return response()->json( [
            'status'    => 200,
            'employees' => $employees,
        ] );
    }

    public function update( Request $request, $id ) {

        // return $request->all();
        // Validate the request data
        $validator = Validator::make( $request->all(), [
            'name'           => 'required|string|max:255',
            'email'          => 'required|string|email|max:255|unique:users,email,' . $request->user_id,
            'number'         => 'required|string|max:20|unique:users,number,' . $request->user_id,
            'status'         => 'required|string|max:20',
            'vendor_role_id' => 'required',
        ] );

        // Check if the validation fails
        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        }

        // Find the vendor employee by its ID
        $employee = VendorEmployee::findOrFail( $id );
        // Update the vendor employee attributes
        $employee->update( $request->only( [
            'vendor_role_id',
        ] ) );

        // Find the user by the vendor employee's user_id
        $user = User::findOrFail( $employee->user_id );
        // Update the user attributes
        $user->update( [
            'name'   => $request->name,
            'email'  => $request->email,
            'number' => $request->number,
            'status' => $request->status,
        ] );

        // Return a success response
        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully employee updated',
        ] );
    }

    public function delete( $id ) {

        $employee = VendorEmployee::find( $id );
        $user     = User::find( $employee->user_id );

        if ( $user->id == Auth::id() ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'You can\'t delete your self !',
            ] );
        }

        if ( $employee->vendor_id == vendorId() ) {
            User::find( $employee->user_id )->delete();
            VendorEmployee::where( 'id', $id )->delete();
            return response()->json( [
                'status'  => 200,
                'message' => 'Successfully employee deleted',
            ] );
        }

        return response()->json( [
            'status'  => 404,
            'message' => 'Employee not found !',
        ] );

    }

    public function status( $id ) {
        $user         = User::find( $id );
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
            'isEmployee' => $isEmployee,
            'permission' => $permission,
        ] );
    }

    //--------For Role----------
    public function indexRole() {
        $roles = VendorRole::where( 'vendor_id', vendorId() )->get();
        return response()->json( [
            "status" => 200,
            "roles"  => $roles,
        ] );
    }
    public function storeRole( Request $request ) {

        $user = User::find( vendorId() );

        if ( !$user->usersubscription ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! It seems you are not eligible to access this feature. Please contact the administrator for assistance.',
            ] );
        }

        if ( $user?->usersubscription?->employee_create == null ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! This service is not available with your current subscription. Please contact the administrator for assistance.',
            ] );
        }
        // dd(Auth::user()->vendor_id);
        $validator = Validator::make( $request->all(), [
            'name' => 'required|unique:vendor_roles,name,NULL,id,vendor_id,' . vendorId(),
        ] );

        // Check if the validation fails
        // if ( $validator->fails() ) {
        //     return response()->json( [
        //         'status'  => 400,
        //         'message' => 'Validation failed',
        //         'errors'  => $validator->errors(),
        //     ] );
        // }
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
        // $role                           = new VendorRole();
        // $role->name                     = $request->name;
        // $role->user_id                  = Auth::id();
        // $role->vendor_id                = vendorId();
        // $role->products                 = $request->products;
        // $role->add_product              = $request->add_product;
        // $role->all_product              = $request->all_request;
        // $role->active_product           = $request->active_product;
        // $role->pending_product          = $request->pending_product;
        // $role->edit_product             = $request->edit_product;
        // $role->reject_product           = $request->reject_product;
        // $role->warehouse                = $request->warehouse;
        // $role->unit                     = $request->unit;
        // $role->color                    = $request->color;
        // $role->variation                = $request->variation;
        // $role->order                    = $request->order;
        // $role->add_order                = $request->add_order;
        // $role->all_order                = $request->all_order;
        // $role->hold_order               = $request->hold_order;
        // $role->pending_order            = $request->pending_order;
        // $role->receive_order            = $request->receive_order;
        // $role->delivery_processing      = $request->delivery_processing;
        // $role->delivery_order           = $request->delivery_order;
        // $role->cancel_order             = $request->cancel_order;
        // $role->customer                 = $request->customer;
        // $role->pos_sale                 = $request->pos_sale;
        // $role->add_pos_sale             = $request->add_pos_sale;
        // $role->all_pos_sale             = $request->all_pos_sale;
        // $role->payment_history_pos_sale = $request->payment_history_pos_sale;
        // $role->supplier                 = $request->supplier;
        // $role->purchase                 = $request->purchase;
        // $role->add_purchase             = $request->add_purchase;
        // $role->all_purchase             = $request->all_purchase;
        // $role->payment_history_purchase = $request->payment_history_purchase;
        // $role->barcode                  = $request->barcode;
        // $role->barcode_generate         = $request->barcode_generate;
        // $role->barcode_manage           = $request->barcode_manage;
        // $role->setting                  = $request->setting;
        // $role->source                   = $request->source;
        // $role->payment_method           = $request->payment_method;
        // $role->affiliate_request        = $request->affiliate_request;
        // $role->all_request              = $request->all_request;
        // $role->active_request           = $request->active_request;
        // $role->pending_request          = $request->pending_request;
        // $role->reject_request           = $request->reject_request;
        // $role->expired_request          = $request->expired_request;
        // $role->return_list              = $request->return_list;
        // $role->purchase_return          = $request->purchase_return;
        // $role->sale_return              = $request->sale_return;
        // $role->add_wastage              = $request->add_wastage;
        // $role->all_wastage              = $request->all_wastage;
        // $role->report                   = $request->report;
        // $role->stock_report             = $request->stock_report;
        // $role->sales_report             = $request->sales_report;
        // $role->purchase_report          = $request->purchase_report;
        // $role->service_and_order        = $request->service_and_order;
        // $role->due_sales_report         = $request->due_sales_report;
        // $role->warehouse_report         = $request->warehouse_report;
        // $role->create_service           = $request->create_service;
        // $role->all_service              = $request->all_service;
        // $role->service_order            = $request->service_order;
        // $role->coupon                   = $request->coupon;
        // $role->membership               = $request->membership;
        // $role->advertiser               = $request->advertiser;
        // $role->recharge                 = $request->recharge;
        // $role->purchase_service         = $request->purchase_service;
        // $role->all_service_order        = $request->all_service_order;
        // $role->pending_service_order    = $request->pending_service_order;
        // $role->progress_service_order   = $request->progress_service_order;
        // $role->hold_service_order       = $request->hold_service_order;
        // $role->cancel_service_order     = $request->cancel_service_order;
        // $role->balance                  = $request->balance;
        // $role->recharge                 = $request->recharge;
        // $role->withdraw                 = $request->withdraw;
        // $role->recharge_history         = $request->recharge_history;
        // $role->create_support           = $request->create_support;
        // $role->all_support              = $request->all_support;
        // $role->chat                     = $request->chat;
        // $role->employee                 = $request->employee;
        // $role->save();

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully role created',
        ] );
    }

    public function showRole( $id ) {
        $role = VendorRole::find( $id );
        return response()->json( [
            "status" => 200,
            "role"   => $role,
        ] );
    }

    public function updateRole( Request $request, $id ) {

        $user = User::find( vendorId() );

        if ( !$user->usersubscription ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! It seems you are not eligible to access this feature. Please contact the administrator for assistance.',
            ] );
        }

        if ( $user?->usersubscription?->employee_create == null ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Oops! This service is not available with your current subscription. Please contact the administrator for assistance.',
            ] );
        }
        // dd(Auth::user()->vendor_id);
        $validator = Validator::make( $request->all(), [
            'name' => 'required|unique:vendor_roles,name,' . $id . ',id,vendor_id,' . vendorId(),
        ] );

        // Check if the validation fails
        // if ( $validator->fails() ) {
        //     return response()->json( [
        //         'status'  => 400,
        //         'message' => 'Validation failed',
        //         'errors'  => $validator->errors(),
        //     ], 400 );
        // }
        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        }
        $data              = $request->all();
        $data['user_id']   = Auth::id();
        $data['vendor_id'] = vendorId();
        VendorRole::find( $id )->update( $data );

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully role updated',
        ] );
    }

    public function deleteRole( $id ) {
        VendorRole::find( $id )->delete();
        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully role deleted',
        ] );
    }
}
