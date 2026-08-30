<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ActivateAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['nullable', 'in:aamarpay,my-wallet,free'],
            'value'          => ['nullable', 'string', 'max:255'],
            'return_url'     => ['nullable', 'string', 'max:500'],
        ];
    }

    public function failedValidation( Validator $validator ): void
    {
        throw new HttpResponseException( response()->json( [
            'success' => false,
            'message' => 'Validation errors',
            'data'    => $validator->errors(),
        ], 422 ) );
    }
}
