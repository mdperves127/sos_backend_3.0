{{ config('app.name', 'Affsell') }}

Verify your email

Hello{{ ! empty($user->name) ? ', ' . $user->name : '' }},

Please verify your email address using the code below to activate your account.

Verification code: {{ $user->verify_code }}

This code expires in {{ (int) ($expiresMinutes ?? 5) }} minutes.

If you did not create an account, you can ignore this message.

---
This is a transactional message from {{ config('app.name', 'Affsell') }}.
If you did not request this, you can safely ignore this email.
© {{ date('Y') }} {{ config('app.name', 'Affsell') }}. All rights reserved.
