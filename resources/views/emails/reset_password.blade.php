@extends('emails.layouts.professional', [
    'title' => 'Password Reset',
    'heading' => 'Reset your password',
    'preheader' => 'Your password reset code is ready.',
])

@section('content')
    <p style="margin:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#334155;">
        We received a request to reset your password. Use the code below to continue. This code expires shortly for your security.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:24px 0;">
        <tr>
            <td align="center" style="background-color:#f8fafc;border:1px solid #e2e8f0;padding:24px 16px;">
                <p style="margin:0 0 8px;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#64748b;">
                    Reset code
                </p>
                <p style="margin:0;font-family:Consolas,'Courier New',monospace;font-size:32px;font-weight:700;letter-spacing:6px;color:#392D87;line-height:1.2;">
                    {{ $token }}
                </p>
            </td>
        </tr>
    </table>

    <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#64748b;">
        Enter this code in the app to set a new password. If you did not request a reset, no action is needed.
    </p>
@endsection
