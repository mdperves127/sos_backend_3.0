<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PaymentHistory;

class HistoryController extends Controller {

    function index() {
        $data = PaymentHistory::on( 'mysql' )->where( 'tenant_id', tenant()->id )->latest()->paginate( 12 );
        return $data;
    }

}
