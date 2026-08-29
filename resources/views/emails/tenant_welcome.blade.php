@extends('emails.layouts.professional', [
    'title' => 'Welcome',
    'heading' => 'Welcome aboard',
    'preheader' => 'Your account has been created successfully.',
])

@section('content')
    <p style="margin:0 0 8px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#334155;">
        Hello{{ ! empty($ownerName) ? ', ' . $ownerName : '' }},
    </p>
    <p style="margin:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#334155;">
        Your registration was successful. <strong style="color:#392D87;">{{ $companyName }}</strong> is set up and ready to use.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:24px 0;border:1px solid #e2e8f0;">
        <tr>
            <td style="padding:14px 16px;background-color:#f8fafc;border-bottom:1px solid #e2e8f0;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#64748b;width:40%;">
                Account type
            </td>
            <td style="padding:14px 16px;border-bottom:1px solid #e2e8f0;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#392D87;text-transform:capitalize;">
                {{ $type }}
            </td>
        </tr>
        <tr>
            <td style="padding:14px 16px;background-color:#f8fafc;border-bottom:1px solid #e2e8f0;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#64748b;">
                Login email
            </td>
            <td style="padding:14px 16px;border-bottom:1px solid #e2e8f0;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#392D87;">
                {{ $email }}
            </td>
        </tr>
        <tr>
            <td style="padding:14px 16px;background-color:#f8fafc;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#64748b;">
                Store URL
            </td>
            <td style="padding:14px 16px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#392D87;">
                @php
                    $displayDomain = str_ireplace( 'affsell.org', 'affsell.com', $domainUrl );
                    $storeUrl = str_starts_with( $displayDomain, 'http' ) ? $displayDomain : 'https://' . $displayDomain;
                @endphp
                <a href="{{ $storeUrl }}" style="color:#392D87;text-decoration:underline;">{{ $displayDomain }}</a>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#334155;">
        Sign in with the email and password you chose during registration to access your dashboard.
    </p>

    <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#64748b;">
        If you need help getting started, reply to this email or contact our support team.
    </p>
@endsection
