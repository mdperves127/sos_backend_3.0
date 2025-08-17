<?php

namespace App\Http\Requests;

use App\Models\CouponRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CouponSendRequest extends FormRequest
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
            'comments'=>['required', function($attribute,$value,$fail){
                if(request('comments') != ''){
                   $data = CouponRequest::query()
                    ->where('user_id',auth()->id())
                    ->whereIn('status',['pending','active'])
                    ->exists();
                    if($data){
                        $fail('You can not send coupon request one more time');
                    }
                }
            } ],
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
