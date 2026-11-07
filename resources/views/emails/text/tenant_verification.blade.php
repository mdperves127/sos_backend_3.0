{{ config('app.name', 'Affsell') }}

Verify your registration

Hello{{ ! empty($userName) ? ', ' . $userName : '' }},

Thank you for registering. Please confirm your email with the verification code below to complete setup.

Verification code: {{ $token }}

This code expires in {{ (int) ($expiresMinutes ?? 5) }} minutes.

Enter this code on the registration screen. Do not share it with anyone.

---
This is a transactional message from {{ config('app.name', 'Affsell') }}.
If you did not request this, you can safely ignore this email.
© {{ date('Y') }} {{ config('app.name', 'Affsell') }}. All rights reserved.
