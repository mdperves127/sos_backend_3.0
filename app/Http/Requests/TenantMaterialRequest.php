<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class TenantMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_advertise_banner'     => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp'],
            'tenant_advertise_banner_url' => ['nullable', 'url', 'max:2048'],
        ];
    }

    public function failedValidation( Validator $validator )
    {
        throw new HttpResponseException( response()->json( [
            'success' => false,
            'message' => 'Validation errors',
            'data'    => $validator->errors(),
        ] ) );
    }
}
