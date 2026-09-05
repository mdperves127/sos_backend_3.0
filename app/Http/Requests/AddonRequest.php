<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $photoRules = $this->isMethod( 'post' )
            ? ['required', 'mimes:jpeg,png,jpg,webp']
            : ['nullable', 'mimes:jpeg,png,jpg,webp'];

        return [
            'name'        => ['required', 'string', 'max:255'],
            'photo'       => $photoRules,
            'addon_type'  => ['required', 'in:membership,system'],
            'price'       => ['required', 'numeric', 'min:0'],
            'for_tenant'       => ['required', 'in:dropshipper,merchant,all'],
            'status'           => ['required', 'in:active,inactive'],
            'short_description'=> ['nullable', 'string', 'max:500'],
            'description'      => ['nullable', 'string'],
            'features'         => ['nullable', 'array'],
            'features.*.key'   => ['required', 'string', 'max:255'],
            'features.*.value' => ['required', 'string', 'max:255'],
            'features.*.visibility' => ['required', 'in:private,public'],
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
