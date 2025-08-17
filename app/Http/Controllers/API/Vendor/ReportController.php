<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\PosSales;
use App\Models\PosSalesDetails;
use App\Models\Product;
use App\Models\ProductPurchase;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller {
    public function stockReport() {

        // $stock = Product::where('vendor_id',vendorId())->select('id','name','selling_price','original_price as purchase_price','qty as stock')
        // // ->with('posSaleDetails','purchaseDetails')
        // // ->withCount('posSaleDetails','purchaseDetails')
        // ->withSum('posSaleDetails','qty')
        // ->withSum('purchaseDetails','qty')
        // ->get();

        $startDate = request( 'start_date' );
        $endDate   = request( 'end_date' );
        $query     = Product::where( 'vendor_id', vendorId() )
            ->select( 'id', 'name', 'original_price as purchase_price', 'qty as stock', DB::raw( 'CASE
                    WHEN discount_price IS NULL THEN selling_price
                    ELSE discount_price
                    END AS selling_price' ) )
            ->withSum( 'posSaleDetails', 'qty' )
            ->withSum( 'purchaseDetails', 'qty' );
        if ( $startDate && $endDate ) {

            $startDate = Carbon::parse( request()->input( 'start_date' ) )->startOfDay();
            $endDate   = Carbon::parse( request()->input( 'end_date' ) )->endOfDay();

            $query->whereDate( 'created_at', '>=', $startDate )
                ->whereDate( 'created_at', '<=', $endDate );
        }
        $stock = $query->paginate( 10 )->withQueryString();

        return response()->json( [
            'status'       => 200,
            'stock_report' => $stock,
        ] );
    }

    public function stockShortageReport() {

        $startDate = request( 'start_date' );
        $endDate   = request( 'end_date' );
        $query     = Product::where( 'vendor_id', vendorId() )
            ->whereColumn( 'alert_qty', '>=', 'qty' )
            ->whereHas( 'productVariant' )
            ->select( 'id', 'name', 'original_price as purchase_price', 'alert_qty', 'qty as stock', DB::raw( 'CASE
                    WHEN discount_price IS NULL THEN selling_price
                    ELSE discount_price
                    END AS selling_price' ) );
        if ( $startDate && $endDate ) {

            $startDate = Carbon::parse( request()->input( 'start_date' ) )->startOfDay();
            $endDate   = Carbon::parse( request()->input( 'end_date' ) )->endOfDay();

            $query->whereDate( 'created_at', '>=', $startDate )
                ->whereDate( 'created_at', '<=', $endDate );
        }
        $stockShort = $query->paginate( 10 )->withQueryString();

        return response()->json( [
            'status'     => 200,
            'stockShort' => $stockShort,
        ] );
    }

    public function salesReport() {
        $startDate = request( 'start_date' );
        $endDate   = request( 'end_date' );
        $status    = request( 'status' );

        $query = PosSales::where( 'vendor_id', vendorId() )->select( 'id', 'sale_date', 'barcode', 'customer_id', 'total_price', 'paid_amount', 'due_amount' )
            ->with( ['customer:id,customer_name'] );
        if ( $startDate && $endDate ) {

            $startDate = Carbon::parse( request()->input( 'start_date' ) )->startOfDay();
            $endDate   = Carbon::parse( request()->input( 'end_date' ) )->endOfDay();

            $query->whereDate( 'created_at', '>=', $startDate )
                ->whereDate( 'created_at', '<=', $endDate );
        }

        if ( $status ) {
            $query->where( 'payment_status', $status );
        }
        $sales = $query->paginate( 10 )->withQueryString();
        return response()->json( [
            'status'       => 200,
            'sales_report' => $sales,
        ] );
    }

    public function dueSalesReport() {
        $startDate = request( 'start_date' );
        $endDate   = request( 'end_date' );

        $query = PosSales::where( 'vendor_id', vendorId() )->where( 'due_amount', '>', 0 )->select( 'id', 'sale_date', 'barcode', 'customer_id', 'total_price', 'paid_amount', 'due_amount' )
            ->with( ['customer:id,customer_name'] );
        if ( $startDate && $endDate ) {

            $startDate = Carbon::parse( request()->input( 'start_date' ) )->startOfDay();
            $endDate   = Carbon::parse( request()->input( 'end_date' ) )->endOfDay();

            $query->whereDate( 'created_at', '>=', $startDate )
                ->whereDate( 'created_at', '<=', $endDate );
        }
        $due_sales = $query->paginate( 10 )->withQueryString();
        return response()->json( [
            'status'           => 200,
            'due_sales_report' => $due_sales,
        ] );
    }

    public function purchaseReport() {
        $startDate = request( 'start_date' );
        $endDate   = request( 'end_date' );

        $query = ProductPurchase::where( 'vendor_id', vendorId() )->select( 'id', 'purchase_date', 'chalan_no', 'supplier_id', 'total_price', 'paid_amount', 'due_amount' )
            ->with( ['supplier:id,supplier_name'] );
        if ( $startDate && $endDate ) {

            $startDate = Carbon::parse( request()->input( 'start_date' ) )->startOfDay();
            $endDate   = Carbon::parse( request()->input( 'end_date' ) )->endOfDay();

            $query->whereDate( 'created_at', '>=', $startDate )
                ->whereDate( 'created_at', '<=', $endDate );
        }
        $purchase = $query->paginate( 10 )->withQueryString();

        return response()->json( [
            'status'          => 200,
            'purchase_report' => $purchase,
        ] );
    }

    public function warehouseReport() {
        $startDate = request( 'start_date' );
        $endDate   = request( 'end_date' );

        $query = Warehouse::where( 'vendor_id', vendorId() )->where( 'status', 'active' )->select( 'id', 'name as warehouse_name' )
            ->with( ['products:id,name,selling_price,original_price as purchase_price,qty as stock,warehouse_id'] );
        if ( $startDate && $endDate ) {

            $startDate = Carbon::parse( request()->input( 'start_date' ) )->startOfDay();
            $endDate   = Carbon::parse( request()->input( 'end_date' ) )->endOfDay();

            $query->whereDate( 'created_at', '>=', $startDate )
                ->whereDate( 'created_at', '<=', $endDate );
        }
        $warehouse = $query->paginate( 10 )->withQueryString();

        return response()->json( [
            'status'           => 200,
            'warehouse_report' => $warehouse,
        ] );
    }

    public function topRepeatCustomer() {
        $topCustomers = PosSales::select( 'customer_id', DB::raw( 'COUNT(*) as order_count' ) )
            ->groupBy( 'customer_id' )
            ->orderByDesc( 'order_count' )
            ->limit( 100 )
            ->with( 'customer', function ( $query ) {
                $query->select( 'id', 'customer_name', 'customer_id', 'email', 'phone', 'status' );
            } )
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'        => 200,
            'top_customers' => $topCustomers,
        ] );
    }

    public function salesReportVariant() {
        $productId = request( 'product_id' );

        $variantSalesReport = PosSalesDetails::when( $productId, function ( $query ) use ( $productId ) {
            $query->where( 'product_id', $productId );
        } )->with( 'product', 'size', 'color', 'unit' )
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'             => 200,
            'variantSalesReport' => $variantSalesReport,
        ] );
    }

    public function getProductIdsFromSalesDetails() {
        $productIds = PosSalesDetails::distinct()->pluck( 'product_id' );

        // Fetch the corresponding Product models using eager loading
        $products = Product::whereIn( 'id', $productIds )->select( 'id', 'name' )->get();

        return response()->json( [
            'status'   => 200,
            'products' => $products,
        ] );
    }

    public function salesReportDailyProductWise() {
        $startDate = request( 'from_date' );
        $endDate   = request( 'to_date' );
        $today     = today()->toDateString();
        $productId = request( 'product_id' );
        $sourceId  = request( 'source_id' );
        $status    = request( 'status' );

        $variantSalesReport = PosSalesDetails::with( 'product', 'size', 'color', 'unit' )
            ->when( $productId, function ( $query ) use ( $productId ) {
                $query->where( 'product_id', $productId );
            } )

            ->when( $startDate && $endDate, function ( $query ) use ( $startDate, $endDate ) {
                $query->whereDate( 'created_at', '>=', $startDate )
                    ->whereDate( 'created_at', '<=', $endDate );
            } )
            ->when( $sourceId, function ( $query ) use ( $sourceId ) {
                $query->whereHas( 'posSale', function ( $q ) use ( $sourceId ) {
                    $q->where( 'source_id', $sourceId );
                } );
            } )
            ->when( $status, function ( $query ) use ( $status ) {
                $query->whereHas( 'posSale', function ( $q ) use ( $status ) {
                    $q->where( 'payment_status', $status );
                } );
            } )
            ->whereDate( 'created_at', $today )
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'             => 200,
            'variantSalesReport' => $variantSalesReport,
        ] );
    }

}
