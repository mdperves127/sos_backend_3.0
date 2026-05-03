<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class TIcketReviewRequest extends FormRequest
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
        $existsRule = Rule::exists( 'mysql.support_boxes', 'id' )
            ->where( 'is_close', 1 )
            ->where( 'user_id', auth()->id() );
        if ( function_exists( 'tenant' ) && tenant() ) {
            $existsRule->where( 'tenant_id', tenant()->id );
        }

        return [
            'support_box_id' => ['required', $existsRule],
            'rating'         => 'required|numeric|min:1|max:5',
            'rating_comment' => 'nullable',
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
