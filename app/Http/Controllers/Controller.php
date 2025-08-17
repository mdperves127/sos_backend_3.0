<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController {
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    function response( $message ) {
        return response()->json(
            [
                'status'  => 200,
                'data'    => 'success',
                'message' => $message,
            ]
        );
    }

    function permissionmessage() {
        return response()->json(
            [
                'data'    => 'success',
                'message' => 'You have no permission to access this page',
            ]
        );
    }

    function employeeMessage() {

        return response()->json( [
            'status'  => 400,
            'message' => 'No permission',
        ] );

    }

}
