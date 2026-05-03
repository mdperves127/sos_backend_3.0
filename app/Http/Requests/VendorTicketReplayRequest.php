<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class VendorTicketReplayRequest extends FormRequest {
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
        // return [
        //     'support_box_id'=>['required',Rule::exists('mysql.support_boxes','id')->where('user_id',userid())->where('is_close',0)],
        //     'description'=>'required',
        //     'file'=>['nullable','file']
        // ];

        $existsRule = Rule::exists( 'mysql.support_boxes', 'id' )
            ->where( 'is_close', 0 )
            ->where( 'user_id', auth()->id() );
        if ( function_exists( 'tenant' ) && tenant() ) {
            $existsRule->where( 'tenant_id', tenant()->id );
        }

        return [
            'support_box_id' => [
                'required',
                $existsRule,
            ],
            'description'    => 'required_without:file',
            'file'           => 'required_without:description|nullable|file',
        ];
    }

    public function failedValidation( Validator $validator ) {
        throw new HttpResponseException( response()->json( [
            'success' => false,
            'message' => 'Insert any text or file',
            'data'    => $validator->errors(),
        ] ) );
    }
}
