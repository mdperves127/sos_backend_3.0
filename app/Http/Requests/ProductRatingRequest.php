<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ProductRatingRequest extends FormRequest
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
            'order_id'=>['required',function($attribute,$value,$fail){
                if(request('order_id') != ''){
                 $data =   Order::query()
                    ->doesntHave('productrating')
                    ->where(['affiliator_id'=> auth()->id(),'id'=>request('order_id')])
                    ->whereIn('status',['delivered','return'])
                    ->exists();
                    if(!$data){
                        $fail('You have not access to rating!');
                    }
                }
            }],
            'rating'=>['required','numeric', 'min:1','max:5' ],
            'comment'=>['required']
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
