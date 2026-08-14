<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Services\EpsPaymentCompletionService;
use Illuminate\Http\Request;
use Throwable;

class PaymentRecoveryController extends Controller
{
    /**
     * Manually complete a successful EPS payment that never hit the callback
     * (e.g. SPA 404 after bank debit).
     *
     * POST /api/admin/payment/complete-eps
     * body: { "merchant_transaction_id": "6a7f36bc33f518", "callback": "recharge" }
     */
    public function completeEps( Request $request )
    {
        $data = $request->validate( [
            'merchant_transaction_id' => 'required|string',
            'callback'                => 'nullable|string|in:recharge,subscription,renew',
        ] );

        try {
            $url = app( EpsPaymentCompletionService::class )->completeByTransactionId(
                $data['merchant_transaction_id'],
                $data['callback'] ?? null
            );
        } catch ( Throwable $e ) {
            return response()->json( [
                'status'  => 422,
                'message' => $e->getMessage(),
            ], 422 );
        }

        return $this->responseData( [
            'message'      => 'Payment completed successfully.',
            'redirect_url' => $url,
        ] );
    }
}
