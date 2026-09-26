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
     * Accept legacy `number` field as phone (admin/frontend payloads).
     * Normalize to BD local format: 01XXXXXXXXX (11 digits).
     */
    protected function prepareForValidation(): void
    {
        $phone = $this->input( 'phone', $this->input( 'number' ) );

        if ( is_string( $phone ) ) {
            $phone = preg_replace( '/[\s\-\(\)]+/', '', $phone );

            // +8801XXXXXXXXX or 8801XXXXXXXXX → 01XXXXXXXXX
            if ( preg_match( '/^\+?880(1[3-9]\d{8})$/', $phone, $matches ) ) {
                $phone = $matches[1];
            }
        }

        if ( $phone !== null && $phone !== '' ) {
            $this->merge( [
                'phone' => $phone,
            ] );
        }
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
            'domain'       => 'required|string|max:255|regex:/^[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]$/',
            'email'        => 'required|email|max:255|unique:mysql.tenants,email',
            'phone'        => [
                'required',
                'string',
                'size:11',
                'regex:/^01[3-9][0-9]{8}$/',
                'unique:mysql.tenants,phone',
            ],
            'number'       => 'nullable|string|max:20',
            'address'      => 'nullable|string|max:500',
            'owner_name'   => 'required|string|max:255',
            'password'     => 'required|string|min:8|confirmed|max:255',
            'type'         => 'required|string|in:dropshipper,merchant',
            'status'       => 'nullable|string|in:pending,active,blocked',
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
            if ( $validator->errors()->isNotEmpty() ) {
                return;
            }

            $domain     = $this->input('domain');
            $fullDomain = $this->resolveFullDomain( $domain );
            $tenantId   = preg_replace('/[^a-zA-Z0-9]/', '', $domain);

            if (\Stancl\Tenancy\Database\Models\Domain::where('domain', $fullDomain)->exists()) {
                $validator->errors()->add('domain', 'This domain is already registered.');
            }

            if (\App\Models\Tenant::where('id', $tenantId)->exists()) {
                $validator->errors()->add('domain', 'A tenant with this domain name already exists.');
            }
        });
    }

    private function resolveFullDomain( string $domain ): string
    {
        if ( str_contains( $domain, '.' ) ) {
            return $domain;
        }

        $mainDomain = config( 'cpanel.main_domain' );
        if ( $mainDomain ) {
            return $domain . '.' . $mainDomain;
        }

        if ( config( 'app.env' ) === 'local' ) {
            return $domain . '.localhost';
        }

        return $domain;
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
            'domain.regex' => 'Domain may only contain letters, numbers, and hyphens, and cannot start or end with a hyphen.',
            'domain.unique' => 'This domain is already registered.',

            'email.required' => 'Email is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.max' => 'Email cannot exceed 255 characters.',
            'email.unique' => 'This email is already registered.',

            'phone.required' => 'Phone number is required.',
            'phone.string'   => 'Phone must be a string.',
            'phone.size'     => 'Phone number must be exactly 11 digits.',
            'phone.regex'    => 'Phone number must be a valid Bangladeshi mobile number (01XXXXXXXXX).',
            'phone.unique'   => 'This phone number is already registered.',

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
