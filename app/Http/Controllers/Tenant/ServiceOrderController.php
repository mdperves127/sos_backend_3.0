<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;

class ServiceOrderController extends Controller
{
    public function serviceOrderCount() {
        $all = ServiceOrder::on('mysql')->where('tenant_id', tenant()->id)->count();
        $success = ServiceOrder::on('mysql')->where('tenant_id', tenant()->id)->where('status', 'success')->count();
        $delivered = ServiceOrder::on('mysql')->where('tenant_id', tenant()->id)->where('status', 'delivered')->count();
        $revision = ServiceOrder::on('mysql')->where('tenant_id', tenant()->id)->where('status', 'revision')->count();
        $pending = ServiceOrder::on('mysql')->where('tenant_id', tenant()->id)->where(['status'=> 'pending','is_paid'=>1])->count();
        $canceled = ServiceOrder::on('mysql')->where('tenant_id', tenant()->id)->where('status', 'canceled')->count();
        $progress = ServiceOrder::on('mysql')->where('tenant_id', tenant()->id)->where('status', 'progress')->count();

        return response()->json([
            'all' => $all,
            'success' => $success,
            'delivered' => $delivered,
            'revision' => $revision,
            'pending' => $pending,
            'canceled' => $canceled,
            'progress' => $progress,
        ]);
    }
}
