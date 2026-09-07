<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Installment;
use App\Models\PosSales;
use App\Service\Vendor\PosInstallmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PosInstallmentController extends Controller
{
    public function index()
    {
        return response()->json( [
            'status' => 200,
            'data'   => PosInstallmentService::index(),
        ] );
    }

    public function show( $saleId )
    {
        $sale = PosSales::where( 'id', $saleId )
            ->where( 'vendor_id', vendorId() )
            ->whereHas( 'installments' )
            ->first();

        if ( ! $sale ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Installment order not found.',
            ], 404 );
        }

        return response()->json( [
            'status' => 200,
            'data'   => PosInstallmentService::planSummary( $sale ),
        ] );
    }

    public function showInstallment( $installmentId )
    {
        $installment = Installment::where( 'id', $installmentId )
            ->whereHas( 'posSale', fn ( $q ) => $q->where( 'vendor_id', vendorId() ) )
            ->first();

        if ( ! $installment ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Installment not found.',
            ], 404 );
        }

        return response()->json( [
            'status' => 200,
            'data'   => PosInstallmentService::formatInstallment( $installment ),
        ] );
    }

    public function addPayment( Request $request, $installmentId )
    {
        $installment = Installment::where( 'id', $installmentId )
            ->whereHas( 'posSale', fn ( $q ) => $q->where( 'vendor_id', vendorId() ) )
            ->with( 'posSale' )
            ->first();

        if ( ! $installment || ! $installment->posSale ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Installment not found.',
            ], 404 );
        }

        $validator = Validator::make( $request->all(), [
            'amount'            => 'required|numeric|min:0.01',
            'payment_method_id' => 'required|exists:payment_methods,id',
            'note'              => 'nullable|string|max:1000',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 400 );
        }

        try {
            $payment = PosInstallmentService::recordPayment(
                $installment->posSale,
                $installment,
                (float) $request->amount,
                (int) $request->payment_method_id,
                true,
                $request->note
            );
        } catch ( ValidationException $e ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Validation error',
                'errors'  => $e->errors(),
            ], 400 );
        }

        $installment->refresh();
        $sale = $installment->posSale->fresh( ['installments'] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Installment payment recorded successfully.',
            'data'    => [
                'payment'     => $payment,
                'installment' => PosInstallmentService::formatInstallment( $installment ),
                'order'       => [
                    'id'             => $sale->id,
                    'paid_amount'    => (float) $sale->paid_amount,
                    'due_amount'     => (float) $sale->due_amount,
                    'payment_status' => $sale->payment_status,
                ],
            ],
        ] );
    }

    public function paymentHistory( $installmentId )
    {
        $installment = Installment::where( 'id', $installmentId )
            ->whereHas( 'posSale', fn ( $q ) => $q->where( 'vendor_id', vendorId() ) )
            ->first();

        if ( ! $installment ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Installment not found.',
            ], 404 );
        }

        $data = PosInstallmentService::formatInstallment( $installment );

        return response()->json( [
            'status' => 200,
            'data'   => [
                'installment' => [
                    'id'                 => $data['id'],
                    'installment_number' => $data['installment_number'],
                    'amount'             => $data['amount'],
                    'paid_amount'        => $data['paid_amount'],
                    'remaining_amount'   => $data['remaining_amount'],
                    'status'             => $data['status'],
                ],
                'payments' => $data['payments'],
            ],
        ] );
    }

    public function customerInstallments( $customerId )
    {
        $summary = PosInstallmentService::customerInstallmentSummary( (int) $customerId );

        if ( ! $summary ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Customer not found.',
            ], 404 );
        }

        return response()->json( [
            'status' => 200,
            'data'   => $summary,
        ] );
    }
}
