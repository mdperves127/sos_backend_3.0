<?php

namespace App\Http\Requests;

use App\Models\Coupon;
use App\Models\Subscription;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class BuysubscriptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'subscription_id' => ['required', function ($attribute, $value, $fail) {
                if (!Subscription::on('mysql')->where('id', $value)->exists()) {
                    return $fail('The selected subscription is invalid.');
                }
            }],
            'coupon_id' => ['nullable', function ($attribute, $value, $fail) {
                if ($value && !Coupon::on('mysql')->where('id', $value)->exists()) {
                    return $fail('The selected coupon is invalid.');
                }
                if (request('subscription_id') != '' && request('coupon_id') != '') {
                    $subscription = Subscription::on('mysql')->find(request('subscription_id'));
                    $coupon = Coupon::on('mysql')->find(request('coupon_id'));

                    if ($subscription && $coupon && ($subscription->subscription_amount < $coupon->amount || $subscription->subscription_amount == 0)) {
                        return $fail('You can not use this coupon');
                    }
                }
            }],
            'payment_type' => ['required', Rule::in(['my-wallet', 'aamarpay','free'])],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success'   => false,
            'message'   => 'Validation errors',
            'data'      => $validator->errors()
        ]));
    }
}
