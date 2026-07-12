<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use App\Models\Tenant;

class ProductOrderRequest extends FormRequest {
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize() {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules() {
        $order = Order::find( $this->route( 'id' ) );
        if ( ! $order ) {
            throw new HttpResponseException( response()->json( [
                'success' => false,
                'message' => 'Order not found',
            ], 404 ) );
        }

        $currentStatus = strtolower( trim( (string) ( $order->status ?? '' ) ) );
        $statusRules   = [
            'hold'       => ['cancel', 'pending'],
            'pending'    => ['cancel', 'received', 'progress'],
            'received'   => ['cancel', 'processing', 'progress'],
            'processing' => ['cancel', 'ready'],
            'ready'      => ['cancel', 'progress'],
            'progress'   => ['return', 'delivered'],
        ];

        if ( ! isset( $statusRules[$currentStatus] ) ) {
            throw new HttpResponseException( response()->json( [
                'success' => false,
                'message' => 'Order status cannot be changed from: ' . ( $currentStatus ?: 'unknown' ),
            ], 422 ) );
        }

        $invalidstatus = function ( $attribute, $value, $fail ) use ( $currentStatus ) {
            if ( in_array( $currentStatus, ['return', 'delivered', 'cancel'], true ) ) {
                $fail( 'Not possible to change current status' );
            }
        };

        $vendorbalance = function ( $attribute, $value, $fail ) use ( $order, $currentStatus ) {
            if ( $currentStatus !== 'hold' || request( 'status' ) === 'cancel' ) {
                return;
            }

            if ( ! $this->orderRequiresAffiliateCommissionHold( $order ) ) {
                return;
            }

            $tenant = function_exists( 'tenant' ) && tenant()
                ? Tenant::on( 'mysql' )->find( tenant()->id )
                : null;

            if ( ! $tenant || (float) $tenant->balance < (float) ( $order->afi_amount ?? 0 ) ) {
                $fail( 'Balance not available!' );
            }
        };

        return [
            'status'      => [
                'required',
                Rule::in( $statusRules[$currentStatus] ),
                $invalidstatus,
                $vendorbalance,
            ],
            'reason'      => 'required_if:status,cancel,return',
            // 'delivery_id' => 'required_if:status,progress',
        ];
    }

    private function orderRequiresAffiliateCommissionHold( Order $order ): bool {
        if ( (int) ( $order->affiliator_id ?? 0 ) <= 0 ) {
            return false;
        }

        if ( (float) ( $order->afi_amount ?? 0 ) <= 0 ) {
            return false;
        }

        return ! $this->isDirectWebsiteOrder( $order );
    }

    private function isDirectWebsiteOrder( Order $order ): bool {
        return in_array(
            (string) ( $order->order_media ?? '' ),
            ['website', 'website-guest', 'Direct'],
            true
        );
    }

    public function failedValidation( Validator $validator ) {
        throw new HttpResponseException( response()->json( [
            'success' => false,
            'message' => 'Validation errors',
            'data'    => $validator->errors(),
        ], 422 ) );
    }
}
