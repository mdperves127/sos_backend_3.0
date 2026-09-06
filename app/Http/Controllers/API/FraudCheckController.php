<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\FraudCheckService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class FraudCheckController extends Controller
{
    public function check( Request $request, FraudCheckService $fraudCheckService )
    {
        $validator = Validator::make( $request->all(), [
            'phone' => ['required', 'string', 'min:10', 'max:20'],
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'success' => false,
                'message' => 'Validation errors',
                'errors'  => $validator->errors(),
            ], 422 );
        }

        try {
            $data = $fraudCheckService->check( (string) $request->input( 'phone' ) );

            return response()->json( [
                'success' => true,
                'data'    => $data,
            ] );
        } catch ( RuntimeException $e ) {
            $status = str_contains( strtolower( $e->getMessage() ), 'invalid' ) ? 422 : 502;

            return response()->json( [
                'success' => false,
                'message' => $e->getMessage(),
                'data'    => null,
            ], $status );
        } catch ( Throwable $e ) {
            report( $e );

            return response()->json( [
                'success' => false,
                'message' => 'Unable to complete fraud check.',
                'data'    => null,
            ], 500 );
        }
    }
}
