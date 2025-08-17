<?php

namespace App\Services\Vendor;
use App\Models\Supplier;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Warehouse;
use App\Models\Unit;
use App\Models\Color;
use App\Models\Customer;
use App\Models\Size;
use App\Models\SaleOrderResource;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Auth;

/**
 * Class VariantApiService.
 */
class VariantApiService
{
    static function variationApi()
    {
        $data = [
            'supplier' => Supplier::latest()->where('vendor_id', vendorId())->where('status','active')->select('id', 'supplier_name','business_name')->get(),
            'unit' => Unit::where(['status' => 'active', 'vendor_id' => vendorId()])->select('id', 'unit_name')->get(),
            'utility' => Color::where(['status' => 'active', 'vendor_id' => vendorId()])->select('id', 'name')->get(),
            'variation' => Size::where(['status' => 'active', 'vendor_id' => vendorId()])->select('id', 'name')->get(),
            'payment_method' => PaymentMethod::where(['status' => 'active', 'vendor_id' => vendorId()])->select('id', 'payment_method_name', 'acc_no')->get(),
            'category' => Category::whereStatus('active')->with('subcategory',function($q){
                        $q->select('id','name','category_id');
                    })
                    ->select('id','name')->get(),
            'brand' => Brand::whereStatus('active')->select('id','name')->get(),
            'warehouse' => Warehouse::where(['status' => 'active','vendor_id'=> vendorId()])->latest()->select('id','name')->get(),
            'customer' => Customer::where('vendor_id',vendorId())->where('status','active')->select('id','customer_name','phone','email','address')->get(),
            'resource' => SaleOrderResource::latest()->where('vendor_id',vendorId())->where('status','active')->select('id','name')->get(),
        ];

        return $data;
    }
}
