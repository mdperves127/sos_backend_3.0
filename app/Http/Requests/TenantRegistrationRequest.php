<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TenantRegistrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_name' => 'required|string|max:255',
            'domain' => 'required|string|max:255|regex:/^[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]$/',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'owner_name' => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed|max:255',
            'type' => 'required|string|in:merchant,dropshipper',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
        public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $domain = $this->input('domain');

            // Transform domain for validation check
            $fullDomain = $domain;
            if (!str_contains($domain, '.localhost') && !str_contains($domain, '.local') && !str_contains($domain, '.')) {
                $fullDomain = $domain . '.localhost';
            }

            // Generate tenant ID from domain
            $tenantId = preg_replace('/[^a-zA-Z0-9]/', '', $domain);

            // Check if the transformed domain already exists
            if (\Stancl\Tenancy\Database\Models\Domain::where('domain', $fullDomain)->exists()) {
                $validator->errors()->add('domain', 'This domain is already registered.');
            }

            // Check if tenant ID already exists
            if (\App\Models\Tenant::where('id', $tenantId)->exists()) {
                $validator->errors()->add('domain', 'A tenant with this domain name already exists.');
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'company_name.required' => 'Company name is required.',
            'company_name.string' => 'Company name must be a string.',
            'company_name.max' => 'Company name cannot exceed 255 characters.',

            'domain.required' => 'Domain is required.',
            'domain.string' => 'Domain must be a string.',
            'domain.max' => 'Domain cannot exceed 255 characters.',
            'domain.unique' => 'This domain is already registered.',

            'email.required' => 'Email is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.max' => 'Email cannot exceed 255 characters.',

            'phone.string' => 'Phone must be a string.',
            'phone.max' => 'Phone number cannot exceed 20 characters.',

            'address.string' => 'Address must be a string.',
            'address.max' => 'Address cannot exceed 500 characters.',

            'owner_name.required' => 'Owner name is required.',
            'owner_name.string' => 'Owner name must be a string.',
            'owner_name.max' => 'Owner name cannot exceed 255 characters.',

            'password.required' => 'Password is required.',
            'password.string' => 'Password must be a string.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.max' => 'Password cannot exceed 255 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
        ];
    }
}
