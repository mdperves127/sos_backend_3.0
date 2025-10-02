<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\Settings;


class BankController extends Controller {

    function index()
    {
        return response()->json([
            'status' => 200,
            'message' => Bank::on('mysql')->latest()->get()
        ]);
    }
}
