{{ config('app.name', 'Affsell') }}

Reset your password

Hello{{ ! empty($userName) ? ', ' . $userName : '' }},

We received a request to reset your password. Use the code below to continue.

Reset code: {{ $token }}

This code expires in {{ (int) ($expiresMinutes ?? 5) }} minutes.

Enter this code in the app to set a new password. If you did not request a reset, no action is needed.

---
This is a transactional message from {{ config('app.name', 'Affsell') }}.
If you did not request this, you can safely ignore this email.
© {{ date('Y') }} {{ config('app.name', 'Affsell') }}. All rights reserved.
