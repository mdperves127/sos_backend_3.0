<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class OrderEditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'             => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone'            => ['sometimes', 'nullable', 'string', 'min:10', 'max:30'],
            'email'            => ['sometimes', 'nullable', 'email', 'max:255'],
            'city'             => ['sometimes', 'nullable', 'string', 'max:255'],
            'address'          => ['sometimes', 'nullable', 'string', 'min:5', 'max:2000'],
            'customer'         => ['sometimes', 'array'],
            'customer.name'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.phone'    => ['sometimes', 'nullable', 'string', 'min:10', 'max:30'],
            'customer.email'    => ['sometimes', 'nullable', 'email', 'max:255'],
            'customer.city'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.address'  => ['sometimes', 'nullable', 'string', 'min:5', 'max:2000'],
            'delivery_area'    => ['sometimes', 'nullable', 'integer'],
            'pickup_area'      => ['sometimes', 'nullable', 'integer'],
            'additional_note'  => ['sometimes', 'nullable', 'string', 'max:5000'],
            'internal_note'    => ['sometimes', 'nullable', 'string', 'max:5000'],
            'shipping_date'    => ['sometimes', 'nullable', 'date'],
            'sale_discount'    => ['sometimes', 'numeric', 'min:0'],
            'discount'         => ['sometimes', 'numeric', 'min:0'],
            'delivery_charge'  => ['sometimes', 'numeric', 'min:0'],
            'paid_amount'      => ['sometimes', 'numeric', 'min:0'],
            'qty'              => ['sometimes', 'integer', 'min:1'],
            'items'            => ['sometimes', 'array', 'min:1'],
            'items.*.id'       => ['nullable', 'integer'],
            'items.*.product_id' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_id'  => ['nullable', 'integer'],
            'items.*.size_id'  => ['nullable', 'integer'],
            'items.*.color_id' => ['nullable', 'integer'],
            'items.*.qty'      => ['required_with:items', 'integer', 'min:1'],
            'items.*.sub_qty'  => ['nullable', 'integer', 'min:1'],
            'items.*.rate'     => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function failedValidation( Validator $validator )
    {
        throw new HttpResponseException( response()->json( [
            'status'  => 400,
            'message' => 'Validation errors',
            'errors'  => $validator->errors(),
        ], 400 ) );
    }
}
