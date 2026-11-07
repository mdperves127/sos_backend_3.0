{{ config('app.name', 'Affsell') }}

Welcome aboard

Hello{{ ! empty($ownerName) ? ', ' . $ownerName : '' }},

Your registration was successful. {{ $companyName }} is set up and ready to use.

Account type: {{ $type }}
Login email: {{ $email }}
@php
    $storeUrl = str_starts_with($domainUrl, 'http') ? $domainUrl : 'https://' . $domainUrl;
@endphp
Store URL: {{ $storeUrl }}

Sign in with the email and password you chose during registration to access your dashboard.

If you need help getting started, reply to this email or contact our support team.

---
This is a transactional message from {{ config('app.name', 'Affsell') }}.
If you did not request this, you can safely ignore this email.
© {{ date('Y') }} {{ config('app.name', 'Affsell') }}. All rights reserved.
