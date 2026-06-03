<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Models\ProductRating;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

class TenantProductReviewRequest extends FormRequest {
    public const PURCHASE_STATUSES = [
        'completed',
        'delivered',
        'receive',
        'received',
        'Delivered',
        'Completed',
    ];

    public function authorize(): bool {
        return Auth::check();
    }

    public function rules(): array {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'order_id'   => ['required', 'integer', 'exists:orders,id'],
            'rating'     => ['required', 'numeric', 'min:1', 'max:5'],
            'comment'    => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator( $validator ): void {
        $validator->after( function ( $validator ) {
            if ( $validator->errors()->isNotEmpty() ) {
                return;
            }

            $userId    = auth()->id();
            $productId = (int) $this->product_id;
            $orderId   = (int) $this->order_id;

            if ( ProductRating::where( 'user_id', $userId )->where( 'product_id', $productId )->exists() ) {
                $validator->errors()->add( 'product_id', 'You have already reviewed this product.' );
                return;
            }

            $order = Order::where( 'id', $orderId )
                ->where( 'user_id', $userId )
                ->where( 'product_id', $productId )
                ->whereIn( 'status', self::PURCHASE_STATUSES )
                ->first();

            if ( !$order ) {
                $validator->errors()->add( 'order_id', 'You can only review products you have purchased and received.' );
            }
        } );
    }

    public function failedValidation( Validator $validator ) {
        throw new HttpResponseException( response()->json( [
            'status'            => 400,
            'validation_errors' => $validator->errors(),
        ], 400 ) );
    }
}
