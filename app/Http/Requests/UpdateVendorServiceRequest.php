<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateVendorServiceRequest extends FormRequest
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
            'service_category_id' => [
                'required',
                Rule::exists('service_categories', 'id')->where(function ($query) {
                })
            ],
            'service_sub_category_id' => [
                'required',
                Rule::exists('service_sub_categories','id')->where(function($query){
                })
            ],
            'title'=>'required',
            'description'=>'required',
            'tags'=>'required',
            'contract'=>'required',
            'commission'=>['required','numeric'],
            'commission_type'=>['required',Rule::in(['flat','percentage'])],
            'image'=> ['nullable', 'image', 'mimes:png,jpg,jpeg'],

            'time'=>'required|array|size:3',
            'package_title'=>'required|array|size:3',
            'package_description'=>'required|array|size:3',
            'price'=>'required|array|size:3',
            'revision_max_time'=>'required|array|size:3',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif',
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
